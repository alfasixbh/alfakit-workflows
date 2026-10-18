<?php

declare(strict_types=1);

use AlfaSix\AlfakitWorkflows\Events\ApprovalDelegated;
use AlfaSix\AlfakitWorkflows\Models\Approval;
use AlfaSix\AlfakitWorkflows\Models\ApprovalRequest;
use AlfaSix\AlfakitWorkflows\Models\WorkflowLog;
use AlfaSix\AlfakitWorkflows\Support\Approvals;
use Illuminate\Support\Facades\Event;

use function Pest\Laravel\artisan;
use function Pest\Laravel\travel;

uses(AlfaSix\AlfakitWorkflows\Tests\TestCase::class);

beforeEach(function (): void {
    alfakit()->setCurrentPanel(alfakit()->getPanel('workflows'));
});

it('reminds pending approvers every remindEvery() interval', function (): void {
    $bob = $this->user('Bob', 'manager');
    $invoice = $this->invoice(500, attributes: ['number' => 'INV-9']);
    $invoice->transition('submit', user: $this->user('Ada'));
    alfakit()->setCurrentPanel(null);

    artisan('alfakit-workflows:process')->expectsOutputToContain('reminded 0')->assertSuccessful();
    expect($bob->notifications()->count())->toBe(1);

    travel(25)->hours();
    artisan('alfakit-workflows:process')->expectsOutputToContain('reminded 1')->assertSuccessful();

    // Not again before another day has passed.
    travel(2)->hours();
    artisan('alfakit-workflows:process')->expectsOutputToContain('reminded 0')->assertSuccessful();

    $approval = Approval::query()->sole();
    $reminder = $bob->notifications()->latest()->first();

    expect($approval->reminders_sent)->toBe(1)
        ->and($reminder?->data['title'])->toBe('Reminder: INV-9 is waiting for you')
        ->and($reminder?->data['actions'][0]['url'] ?? null)->toBe('http://localhost/workflows/invoices/'.$invoice->getKey());

    // Past the due date the reminder says it is overdue.
    travel(1)->days();
    artisan('alfakit-workflows:process --no-escalations')->assertSuccessful();

    expect($bob->notifications()->latest()->first()?->data['body'])->toContain('overdue');
});

it('escalates approvals pending past escalateAfter() to the escalation targets', function (): void {
    Event::fake([ApprovalDelegated::class]);
    $bob = $this->user('Bob', 'manager');
    $dora = $this->user('Dora', 'director');
    $invoice = $this->invoice(500);
    $invoice->transition('submit', user: $this->user('Ada'));

    travel(2)->days();
    artisan('alfakit-workflows:process --no-reminders')->expectsOutputToContain('Escalated 0')->assertSuccessful();

    travel(1)->days();
    travel(1)->minutes();
    artisan('alfakit-workflows:process --no-reminders')->expectsOutputToContain('Escalated 1')->assertSuccessful();

    $request = ApprovalRequest::query()->sole();
    $original = $request->approvals()->where('approver_id', (string) $bob->id)->sole();
    $escalated = Approvals::assignmentFor($request, $dora);

    expect($original->status)->toBe(Approval::ESCALATED)
        ->and($escalated?->replaces_id)->toBe($original->id)
        ->and(Approvals::assignmentFor($request, $bob))->toBeNull()
        ->and($dora->notifications()->sole()->data['title'])->toStartWith('Escalated:')
        ->and($invoice->workflowHistory()->where('event', WorkflowLog::ESCALATED)->sole()->properties)->toMatchArray(['from' => 'Bob', 'to' => 'Dora']);

    Event::assertDispatched(ApprovalDelegated::class, fn (ApprovalDelegated $event): bool => $event->escalated && $event->by === null);

    // Runs again do nothing more.
    artisan('alfakit-workflows:process --no-reminders')->expectsOutputToContain('Escalated 0')->assertSuccessful();

    Approvals::approve($escalated, $dora);
    expect($invoice->fresh()?->state)->toBe('approved');
});

it('flags approvals it cannot escalate instead of retrying them', function (): void {
    $this->user('Bob', 'manager');
    $invoice = $this->invoice(500);
    $invoice->transition('submit', user: $this->user('Ada'));

    travel(4)->days();
    artisan('alfakit-workflows:process --no-reminders')->expectsOutputToContain('Escalated 0')->assertSuccessful();

    $approval = Approval::query()->sole();

    expect($approval->status)->toBe(Approval::PENDING)
        ->and($approval->escalated_at)->not->toBeNull();
});

it('ignores approvals of finished requests', function (): void {
    $bob = $this->user('Bob', 'manager');
    $invoice = $this->invoice(500);
    $invoice->transition('submit', user: $ada = $this->user('Ada'));
    Approvals::cancel(ApprovalRequest::query()->sole(), $ada);

    travel(5)->days();
    artisan('alfakit-workflows:process')->expectsOutputToContain('Escalated 0 and reminded 0')->assertSuccessful();

    expect($bob->notifications()->count())->toBe(1);
});
