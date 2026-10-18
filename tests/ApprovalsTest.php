<?php

declare(strict_types=1);

use AlfaSix\AlfakitWorkflows\ApprovalStep;
use AlfaSix\AlfakitWorkflows\Events\ApprovalDelegated;
use AlfaSix\AlfakitWorkflows\Events\ApprovalGranted;
use AlfaSix\AlfakitWorkflows\Events\ApprovalRejected;
use AlfaSix\AlfakitWorkflows\Events\ApprovalRequested;
use AlfaSix\AlfakitWorkflows\Events\TransitionApplied;
use AlfaSix\AlfakitWorkflows\Exceptions\TransitionDenied;
use AlfaSix\AlfakitWorkflows\Models\Approval;
use AlfaSix\AlfakitWorkflows\Models\ApprovalRequest;
use AlfaSix\AlfakitWorkflows\Models\WorkflowLog;
use AlfaSix\AlfakitWorkflows\Support\Approvals;
use AlfaSix\AlfakitWorkflows\Tests\Fixtures\Models\Document;
use AlfaSix\AlfakitWorkflows\Tests\Fixtures\Models\Invoice;
use AlfaSix\AlfakitWorkflows\Tests\Fixtures\Models\WfUser;
use AlfaSix\AlfakitWorkflows\Transition;
use AlfaSix\AlfakitWorkflows\Workflow;
use Illuminate\Support\Facades\Event;

uses(AlfaSix\AlfakitWorkflows\Tests\TestCase::class);

beforeEach(function (): void {
    alfakit()->setCurrentPanel(alfakit()->getPanel('workflows'));
});

/**
 * The open approval of a user on the record's pending request.
 */
function wfApproval(Invoice|Document $record, WfUser $user): Approval
{
    $request = $record->getPendingApprovalRequest();

    return Approvals::assignmentFor($request ?? throw new RuntimeException('Nothing is pending.'), $user) ?? throw new RuntimeException('No open approval for '.$user->name.'.');
}

it('sends a transition that needs approval to the approvers and waits in the pending state', function (): void {
    Event::fake([ApprovalRequested::class, TransitionApplied::class]);
    $ada = $this->user('Ada', 'manager');
    $bob = $this->user('Bob', 'manager');
    $cyd = $this->user('Cyd', 'manager');
    $this->user('Fin', 'finance');
    $invoice = $this->invoice(500, attributes: ['number' => 'INV-1']);

    $invoice->transition('submit', comment: 'Please check', user: $ada);

    $request = $invoice->getPendingApprovalRequest();

    expect($invoice->state)->toBe('submitted')
        ->and($invoice->isAwaitingApproval())->toBeTrue()
        ->and($request?->transition)->toBe('submit')
        ->and($request?->from_state)->toBe('draft')
        ->and($request?->comment)->toBe('Please check')
        ->and($request?->isRequestedBy($ada))->toBeTrue()
        // The requester never approves their own request.
        ->and($request?->approvals()->pluck('approver_id')->all())->toEqualCanonicalizing([(string) $bob->id, (string) $cyd->id])
        ->and($request?->approvals()->first()?->due_at?->toDateString())->toBe(now()->addDays(2)->toDateString())
        ->and($bob->notifications()->sole()->data['title'])->toBe('INV-1 needs your approval')
        ->and($ada->notifications()->count())->toBe(0)
        // Nothing else can happen while it waits.
        ->and($invoice->getAvailableTransitions($this->user('Root', 'admin')))->toBe([]);

    Event::assertDispatched(ApprovalRequested::class, fn (ApprovalRequested $event): bool => $event->step === 'manager' && $event->approvers->count() === 2);
    Event::assertNotDispatched(TransitionApplied::class);
});

it('completes an any-of step with one approval and skips steps that do not apply', function (): void {
    Event::fake([ApprovalGranted::class, TransitionApplied::class]);
    $ada = $this->user('Ada');
    $bob = $this->user('Bob', 'manager');
    $cyd = $this->user('Cyd', 'manager');
    $this->user('Fin', 'finance');
    $invoice = $this->invoice(500);

    $invoice->transition('submit', user: $ada);
    Approvals::approve(wfApproval($invoice, $bob), $bob, 'Looks good');

    $request = ApprovalRequest::query()->sole();

    expect($invoice->fresh()?->state)->toBe('approved')
        ->and($request->status)->toBe(ApprovalRequest::APPROVED)
        ->and($request->approvals()->where('approver_id', (string) $cyd->id)->sole()->status)->toBe(Approval::SKIPPED)
        ->and($ada->notifications()->latest()->first()?->data['title'])->toBe($invoice->number.' was approved')
        ->and($invoice->workflowHistory()->pluck('event')->all())->toBe([WorkflowLog::CREATED, WorkflowLog::REQUESTED, WorkflowLog::APPROVED, WorkflowLog::TRANSITIONED]);

    Event::assertDispatched(ApprovalGranted::class, fn (ApprovalGranted $event): bool => $event->approver->is($bob) && $event->comment === 'Looks good');
    Event::assertDispatched(TransitionApplied::class, fn (TransitionApplied $event): bool => $event->from === 'submitted' && $event->to === 'approved' && $event->request !== null);
});

it('runs sequential steps and needs every approver of an all-of step', function (): void {
    $ada = $this->user('Ada');
    $bob = $this->user('Bob', 'manager');
    $fin = $this->user('Fin', 'finance');
    $fay = $this->user('Fay', 'finance');
    $invoice = $this->invoice(5000);

    $invoice->transition('submit', user: $ada);

    // Finance is not asked before the manager approved.
    expect($fin->notifications()->count())->toBe(0);

    Approvals::approve(wfApproval($invoice, $bob), $bob);
    $request = ApprovalRequest::query()->sole();

    expect($request->current_step)->toBe(1)
        ->and($invoice->fresh()?->state)->toBe('submitted')
        ->and($fin->notifications()->count())->toBe(1);

    Approvals::approve(wfApproval($invoice, $fin), $fin);

    expect($invoice->fresh()?->state)->toBe('submitted')
        ->and($request->fresh()?->status)->toBe(ApprovalRequest::PENDING);

    Approvals::approve(wfApproval($invoice, $fay), $fay);

    expect($invoice->fresh()?->state)->toBe('approved')
        ->and($request->fresh()?->status)->toBe(ApprovalRequest::APPROVED);
});

it('supports a single named approver and approvers from a closure', function (): void {
    $boss = $this->user('Boss');
    $deputy = $this->user('Deputy');

    Document::define(fn () => Workflow::make('doc')
        ->stateColumn('status')
        ->states(['draft', 'published'])
        ->initial('draft')
        ->transitions([
            Transition::make('publish')->from('draft')->to('published')->requiresApproval([
                ApprovalStep::make('boss')->approvers($boss),
                ApprovalStep::make('deputy')->approvers(fn (Document $record): array => [$deputy->getKey()]),
            ]),
        ]));

    $document = Document::query()->create(['title' => 'Spec']);
    $document->transition('publish', user: $this->user('Ada'));

    // Without a pending state the record stays where it was.
    expect($document->status)->toBe('draft');

    Approvals::approve(wfApproval($document, $boss), $boss);
    Approvals::approve(wfApproval($document, $deputy), $deputy);

    expect($document->fresh()?->status)->toBe('published');
});

it('refuses approvals by anyone but the approver, twice, or on another step', function (): void {
    $bob = $this->user('Bob', 'manager');
    $eve = $this->user('Eve');
    $invoice = $this->invoice(500);
    $invoice->transition('submit', user: $this->user('Ada'));
    $approval = wfApproval($invoice, $bob);

    expect(fn () => Approvals::approve($approval, $eve))->toThrow(TransitionDenied::class, 'You are not allowed to do this.');

    Approvals::approve($approval, $bob);

    expect(fn () => Approvals::approve($approval, $bob))->toThrow(TransitionDenied::class, 'This approval is no longer pending.')
        ->and($invoice->workflowHistory()->where('event', WorkflowLog::APPROVED)->count())->toBe(1);
});

it('rejects with a required comment and moves to the rejected state', function (): void {
    Event::fake([ApprovalRejected::class]);
    $ada = $this->user('Ada');
    $bob = $this->user('Bob', 'manager');
    $invoice = $this->invoice(500);
    $invoice->transition('submit', user: $ada);
    $approval = wfApproval($invoice, $bob);

    expect(fn () => Approvals::reject($approval, $bob))->toThrow(TransitionDenied::class, 'Please add a comment.');

    Approvals::reject($approval, $bob, 'Wrong VAT rate');

    $log = $invoice->workflowHistory()->where('event', WorkflowLog::REJECTED)->sole();

    expect($invoice->fresh()?->state)->toBe('rejected')
        ->and(ApprovalRequest::query()->sole()->status)->toBe(ApprovalRequest::REJECTED)
        ->and($log->comment)->toBe('Wrong VAT rate')
        ->and($log->from_state)->toBe('submitted')
        ->and($log->to_state)->toBe('rejected')
        ->and($ada->notifications()->latest()->first()?->data['body'])->toBe('Bob rejected &quot;Submit&quot;. “Wrong VAT rate”');

    Event::assertDispatched(ApprovalRejected::class);

    // The requester can fix it and try again.
    $invoice->refresh()->transition('reopen', comment: 'Fixed', user: $ada);
    expect($invoice->state)->toBe('draft');
});

it('lets an approver delegate and the delegate decide', function (): void {
    Event::fake([ApprovalDelegated::class]);
    $ada = $this->user('Ada');
    $bob = $this->user('Bob', 'manager');
    $dan = $this->user('Dan');
    $invoice = $this->invoice(500);
    $invoice->transition('submit', user: $ada);

    $new = Approvals::delegate(wfApproval($invoice, $bob), $dan->getKey(), $bob, 'On holiday');

    expect($new->isAssignedTo($dan))->toBeTrue()
        ->and($new->replaces?->status)->toBe(Approval::DELEGATED)
        ->and(Approvals::assignmentFor(ApprovalRequest::query()->sole(), $bob))->toBeNull()
        ->and($dan->notifications()->sole()->data['body'])->toBe('Bob handed the step &quot;Manager approval&quot; over to you.')
        ->and($invoice->workflowHistory()->where('event', WorkflowLog::DELEGATED)->sole()->properties)->toMatchArray(['from' => 'Bob', 'to' => 'Dan']);

    Approvals::approve(wfApproval($invoice, $dan), $dan);

    expect($invoice->fresh()?->state)->toBe('approved');
    Event::assertDispatched(ApprovalDelegated::class, fn (ApprovalDelegated $event): bool => ! $event->reassigned && ! $event->escalated);
});

it('refuses delegating to the requester, to the same person or to someone already asked', function (): void {
    $ada = $this->user('Ada');
    $bob = $this->user('Bob', 'manager');
    $cyd = $this->user('Cyd', 'manager');
    $invoice = $this->invoice(500);
    $invoice->transition('submit', user: $ada);
    $approval = wfApproval($invoice, $bob);

    foreach ([$ada, $bob, $cyd, 'missing'] as $target) {
        expect(fn () => Approvals::delegate($approval, $target instanceof WfUser ? $target : 999, $bob))
            ->toThrow(TransitionDenied::class, 'This person cannot take over the approval.');
    }

    expect(fn () => Approvals::delegate($approval, $this->user('Dan'), $cyd))->toThrow(TransitionDenied::class, 'You are not allowed to do this.');
});

it('refuses delegation on steps that are not delegable', function (): void {
    $boss = $this->user('Boss');

    Document::define(fn () => Workflow::make('doc')
        ->stateColumn('status')
        ->states(['draft', 'published'])
        ->initial('draft')
        ->transitions([Transition::make('publish')->from('draft')->to('published')->requiresApproval(ApprovalStep::make('boss')->approvers($boss)->delegable(false))]));

    $document = Document::query()->create(['title' => 'Spec']);
    $document->transition('publish', user: $this->user('Ada'));

    expect(fn () => Approvals::delegate(wfApproval($document, $boss), $this->user('Dan'), $boss))->toThrow(TransitionDenied::class);
});

it('lets authorised users reassign approvals', function (): void {
    $bob = $this->user('Bob', 'manager');
    $dan = $this->user('Dan');
    $root = $this->user('Root', 'admin');
    $invoice = $this->invoice(500);
    $invoice->transition('submit', user: $this->user('Ada'));
    $approval = wfApproval($invoice, $bob);

    expect(fn () => Approvals::delegate($approval, $dan, $this->user('Eve'), reassign: true))->toThrow(TransitionDenied::class, 'You are not allowed to do this.');

    Approvals::delegate($approval, $dan, $root, reassign: true);

    expect(wfApproval($invoice, $dan)->isPending())->toBeTrue()
        ->and($invoice->workflowHistory()->where('event', WorkflowLog::REASSIGNED)->sole()->causer_id)->toBe((string) $root->id);
});

it('lets the requester withdraw a request', function (): void {
    $ada = $this->user('Ada');
    $bob = $this->user('Bob', 'manager');
    $invoice = $this->invoice(500);
    $invoice->transition('submit', user: $ada);
    $request = ApprovalRequest::query()->sole();

    expect(fn () => Approvals::cancel($request, $bob))->toThrow(TransitionDenied::class);

    Approvals::cancel($request, $ada, 'Wrong customer');

    expect($invoice->fresh()?->state)->toBe('draft')
        ->and($request->fresh()?->status)->toBe(ApprovalRequest::CANCELLED)
        ->and($request->approvals()->pluck('status')->unique()->all())->toBe([Approval::SKIPPED])
        ->and($invoice->fresh()?->getAvailableTransitions($ada))->toHaveKey('submit');
});

it('refuses a transition when nobody can approve it and rolls it back', function (): void {
    $invoice = $this->invoice(500);

    expect(fn () => $invoice->transition('submit', user: $this->user('Ada', 'manager')))
        ->toThrow(TransitionDenied::class, 'Nobody can approve the step "Manager approval".')
        ->and($invoice->fresh()?->state)->toBe('draft')
        ->and(ApprovalRequest::query()->count())->toBe(0)
        ->and(WorkflowLog::query()->where('event', WorkflowLog::REQUESTED)->count())->toBe(0);
});

it('lets requesters approve themselves when the workflow allows it', function (): void {
    $ada = $this->user('Ada', 'boss');

    Document::define(fn () => Workflow::make('doc')
        ->stateColumn('status')
        ->states(['draft', 'published'])
        ->initial('draft')
        ->allowSelfApproval()
        ->transitions([Transition::make('publish')->from('draft')->to('published')->requiresApproval(ApprovalStep::make('boss')->role('boss'))]));

    $document = Document::query()->create(['title' => 'Spec']);
    $document->transition('publish', user: $ada);
    Approvals::approve(wfApproval($document, $ada), $ada);

    expect($document->fresh()?->status)->toBe('published');
});

it('finds approvers by permission', function (): void {
    $this->user('Ada');
    $root = $this->user('Root', 'admin');

    Document::define(fn () => Workflow::make('doc')
        ->stateColumn('status')
        ->states(['draft', 'published'])
        ->initial('draft')
        ->transitions([Transition::make('publish')->from('draft')->to('published')->requiresApproval(ApprovalStep::make('admins')->permission('approve-documents'))]));

    Illuminate\Support\Facades\Gate::define('approve-documents', fn (WfUser $user, Document $document): bool => $user->role === 'admin');

    $document = Document::query()->create(['title' => 'Spec']);
    $document->transition('publish', user: $this->user('Writer'));

    expect(ApprovalRequest::query()->sole()->approvals()->pluck('approver_id')->all())->toBe([(string) $root->id]);
});

it('applies only one of two concurrent approvals of the last step', function (): void {
    $bob = $this->user('Bob', 'manager');
    $cyd = $this->user('Cyd', 'manager');
    $invoice = $this->invoice(500);
    $invoice->transition('submit', user: $this->user('Ada'));
    $bobs = wfApproval($invoice, $bob);
    $cyds = wfApproval($invoice, $cyd);

    Approvals::approve($bobs, $bob);

    expect(fn () => Approvals::approve($cyds, $cyd))->toThrow(TransitionDenied::class, 'This approval is no longer pending.')
        ->and($invoice->workflowHistory()->where('event', WorkflowLog::TRANSITIONED)->count())->toBe(1);
});
