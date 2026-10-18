<?php

declare(strict_types=1);

use AlfaSix\AlfakitWorkflows\Actions\WorkflowActions;
use AlfaSix\AlfakitWorkflows\Exceptions\TransitionDenied;
use AlfaSix\AlfakitWorkflows\Models\Approval;
use AlfaSix\AlfakitWorkflows\Models\ApprovalRequest;
use AlfaSix\AlfakitWorkflows\Models\WorkflowLog;
use AlfaSix\AlfakitWorkflows\Pages\ApprovalsInbox;
use AlfaSix\AlfakitWorkflows\Support\Approvals;
use AlfaSix\AlfakitWorkflows\Support\Users;

use function Pest\Laravel\artisan;
use function Pest\Laravel\travel;

uses(AlfaSix\AlfakitWorkflows\Tests\TestCase::class);

/**
 * Alpha: Ada (requester), Bob (manager), Dora (director). Beta: Ben (manager). Max manages both.
 *
 * @return array<string, mixed>
 */
function wfTenants(AlfaSix\AlfakitWorkflows\Tests\TestCase $test): array
{
    $alpha = $test->team('Alpha');
    $beta = $test->team('Beta');
    $ada = $test->user('Ada');
    $bob = $test->user('Bob', 'manager');
    $dora = $test->user('Dora', 'director');
    $ben = $test->user('Ben', 'manager');
    $max = $test->user('Max', 'manager');
    $ada->teams()->attach($alpha);
    $bob->teams()->attach($alpha);
    $dora->teams()->attach($alpha);
    $ben->teams()->attach($beta);
    $max->teams()->attach([$alpha->id, $beta->id]);

    return compact('alpha', 'beta', 'ada', 'bob', 'dora', 'ben', 'max');
}

function wfEnter(string $panel, ?Illuminate\Database\Eloquent\Model $tenant): void
{
    alfakit()->setCurrentPanel(alfakit()->getPanel($panel));
    alfakit()->setTenant($tenant, isQuiet: true);
}

it('only asks approvers who belong to the record\'s tenant and stores the tenant', function (): void {
    ['alpha' => $alpha, 'ada' => $ada, 'bob' => $bob, 'ben' => $ben, 'max' => $max] = wfTenants($this);
    wfEnter('workflows-team', $alpha);
    $invoice = $this->invoice(500, $alpha);

    $invoice->transition('submit', user: $ada);

    $request = ApprovalRequest::query()->sole();

    expect($request->tenant_type)->toBe($alpha->getMorphClass())
        ->and($request->tenant_id)->toBe((string) $alpha->id)
        ->and($request->panel)->toBe('workflows-team')
        ->and($request->approvals()->pluck('approver_id')->all())->toEqualCanonicalizing([(string) $bob->id, (string) $max->id])
        ->and($ben->notifications()->count())->toBe(0)
        ->and(WorkflowLog::query()->where('event', WorkflowLog::REQUESTED)->sole()->tenant_id)->toBe((string) $alpha->id);
});

it('shows each tenant\'s approvals in its own inbox only', function (): void {
    ['alpha' => $alpha, 'beta' => $beta, 'ada' => $ada, 'max' => $max] = wfTenants($this);
    wfEnter('workflows-team', $alpha);
    $this->invoice(500, $alpha)->transition('submit', user: $ada);

    $this->actingAs($max);

    $this->inertiaGet('/workflows-team/alpha/approvals')->assertOk();
    expect(Approvals::inboxQuery($max)->count())->toBe(1);

    wfEnter('workflows-team', $beta);
    expect(Approvals::inboxQuery($max)->count())->toBe(0)
        ->and(ApprovalsInbox::getNavigationBadge())->toBeNull();

    $props = $this->inertiaGet('/workflows-team/beta/approvals')->assertOk()->json('props.page');
    $table = collect($props['content'])->firstWhere('type', 'Table');

    expect($table['records'])->toBe([]);
});

it('refuses decisions on another tenant\'s approval', function (): void {
    ['alpha' => $alpha, 'beta' => $beta, 'ada' => $ada, 'max' => $max] = wfTenants($this);
    wfEnter('workflows-team', $alpha);
    $invoice = $this->invoice(500, $alpha);
    $invoice->transition('submit', user: $ada);
    $approval = Approvals::assignmentFor(ApprovalRequest::query()->sole(), $max);

    wfEnter('workflows-team', $beta);

    expect(fn () => Approvals::approve($approval ?? throw new RuntimeException, $max))->toThrow(TransitionDenied::class, 'This approval is no longer pending.')
        ->and(WorkflowActions::assignment($invoice))->toBeNull()
        ->and($invoice->fresh()?->state)->toBe('submitted');

    wfEnter('workflows-team', $alpha);
    Approvals::approve($approval, $max);

    expect($invoice->fresh()?->state)->toBe('approved');
});

it('never shows another tenant\'s record or its history', function (): void {
    ['alpha' => $alpha, 'beta' => $beta, 'max' => $max] = wfTenants($this);
    $theirs = $this->invoice(500, $beta);
    $this->actingAs($max);

    $this->inertiaGet('/workflows-team/alpha/invoices/'.$theirs->getKey())->assertNotFound();

    $props = $this->inertiaGet('/workflows-team/beta/invoices/'.$theirs->getKey())->assertOk()->json('props.page');
    expect(json_encode($props))->toContain('data-workflow-timeline');
});

it('limits delegates to the members of the request\'s tenant', function (): void {
    ['alpha' => $alpha, 'ada' => $ada, 'bob' => $bob, 'ben' => $ben, 'dora' => $dora] = wfTenants($this);
    wfEnter('workflows-team', $alpha);
    $invoice = $this->invoice(500, $alpha);
    $invoice->transition('submit', user: $ada);
    $approval = Approvals::assignmentFor(ApprovalRequest::query()->sole(), $bob);

    expect(array_values(Users::search('', $alpha, [(string) $bob->id])))->toEqualCanonicalizing(['Ada', 'Dora', 'Max'])
        ->and(fn () => Approvals::delegate($approval ?? throw new RuntimeException, $ben->getKey(), $bob))->toThrow(TransitionDenied::class);

    Approvals::delegate($approval, $dora->getKey(), $bob);

    expect(Approvals::assignmentFor(ApprovalRequest::query()->sole(), $dora))->not->toBeNull();
});

it('escalates in the request\'s tenant from the scheduled command', function (): void {
    ['alpha' => $alpha, 'ada' => $ada, 'dora' => $dora] = wfTenants($this);
    $beta = AlfaSix\Alfakit\Tests\Fixtures\Models\Team::query()->where('slug', 'beta')->sole();
    $outsider = $this->user('Otto', 'director');
    $outsider->teams()->attach($beta);

    wfEnter('workflows-team', $alpha);
    $this->invoice(500, $alpha)->transition('submit', user: $ada);
    wfEnter('workflows-team', null);
    alfakit()->setCurrentPanel(null);

    travel(4)->days();
    artisan('alfakit-workflows:process --no-reminders')->assertSuccessful();

    $escalated = Approval::query()->where('status', Approval::PENDING)->pluck('approver_id')->all();

    expect($escalated)->toContain((string) $dora->id)
        ->not->toContain((string) $outsider->id)
        ->and($dora->notifications()->sole()->data)->toHaveKey(AlfaSix\Alfakit\Notifications\DatabaseNotificationScope::KEY);
});
