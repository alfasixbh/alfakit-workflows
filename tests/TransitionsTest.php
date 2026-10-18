<?php

declare(strict_types=1);

use AlfaSix\AlfakitWorkflows\Events\TransitionApplied;
use AlfaSix\AlfakitWorkflows\Exceptions\TransitionDenied;
use AlfaSix\AlfakitWorkflows\Models\WorkflowLog;
use AlfaSix\AlfakitWorkflows\Support\Transitions;
use AlfaSix\AlfakitWorkflows\Tests\Fixtures\Models\Document;
use AlfaSix\AlfakitWorkflows\Tests\Fixtures\Models\Invoice;
use AlfaSix\AlfakitWorkflows\Transition;
use AlfaSix\AlfakitWorkflows\Workflow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

uses(AlfaSix\AlfakitWorkflows\Tests\TestCase::class);

beforeEach(function (): void {
    alfakit()->setCurrentPanel(alfakit()->getPanel('workflows'));
});

it('lists the transitions allowed for the user and the state', function (): void {
    $ada = $this->user('Ada');
    $admin = $this->user('Root', 'admin');
    $viewer = $this->user('Vic', 'viewer');
    $invoice = $this->invoice();

    expect(array_keys($invoice->getAvailableTransitions($ada)))->toBe(['submit'])
        ->and(array_keys($invoice->getAvailableTransitions($admin)))->toBe(['submit', 'void'])
        // The `update` policy is the default authorisation.
        ->and($invoice->getAvailableTransitions($viewer))->toBe([])
        ->and($invoice->canTransition('pay', $admin))->toBeFalse();
});

it('applies a transition with its form data, comment and using() hook', function (): void {
    $this->actingAs($ada = $this->user('Ada'));
    Event::fake([TransitionApplied::class]);
    $invoice = $this->invoice(attributes: ['state' => 'approved']);

    $invoice->transition('pay', ['reference' => 'TRX-42'], 'Paid by wire');

    $log = $invoice->workflowHistory()->where('event', WorkflowLog::TRANSITIONED)->sole();

    expect($invoice->state)->toBe('paid')
        ->and($invoice->fresh()?->payment_reference)->toBe('TRX-42')
        ->and($invoice->fresh()?->paid_at)->not->toBeNull()
        ->and($log->from_state)->toBe('approved')
        ->and($log->to_state)->toBe('paid')
        ->and($log->transition)->toBe('pay')
        ->and($log->comment)->toBe('Paid by wire')
        ->and($log->data)->toBe(['reference' => 'TRX-42'])
        ->and($log->causer_id)->toBe((string) $ada->getKey());

    Event::assertDispatched(TransitionApplied::class, fn (TransitionApplied $event): bool => $event->transition === 'pay' && $event->from === 'approved' && $event->to === 'paid' && $event->user?->is($ada));
});

it('refuses transitions from the wrong state', function (): void {
    $invoice = $this->invoice();

    expect(fn () => $invoice->transition('pay', ['reference' => 'x'], user: $this->user()))
        ->toThrow(TransitionDenied::class, '"Mark as paid" is not possible from the state "Draft".')
        ->and($invoice->fresh()?->state)->toBe('draft');
});

it('refuses unknown transitions', function (): void {
    expect(fn () => $this->invoice()->transition('teleport'))->toThrow(TransitionDenied::class, 'The transition "teleport" does not exist.');
});

it('checks guards with their message, also on the locked row', function (): void {
    $invoice = $this->invoice(0);
    $ada = $this->user('Ada');

    expect($invoice->getAvailableTransitions($ada))->toBe([]);

    try {
        $invoice->transition('submit', user: $ada);
        $this->fail('The guard did not refuse.');
    } catch (TransitionDenied $exception) {
        expect($exception->reason)->toBe(TransitionDenied::GUARD)
            ->and($exception->getMessage())->toBe('Add an amount first.');
    }

    // A stale instance passes the guard locally, but the transition reads the row again.
    $stale = $this->invoice(500);
    DB::table('wf_invoices')->where('id', $stale->getKey())->update(['total' => 0]);

    expect(fn () => $stale->transition('submit', user: $ada))->toThrow(TransitionDenied::class, 'Add an amount first.');
});

it('passes the user, data and workflow to guards and authorize closures', function (): void {
    $seen = [];

    Document::define(function () use (&$seen): Workflow {
        return Workflow::make('doc')
            ->stateColumn('status')
            ->states(['draft', 'published'])
            ->initial('draft')
            ->transitions([
                Transition::make('publish')
                    ->from('draft')
                    ->to('published')
                    ->authorize(function (Document $record, ?Illuminate\Database\Eloquent\Model $user) use (&$seen): bool {
                        $seen['authorize'] = $user?->getAttribute('name');

                        return $user?->getAttribute('role') === 'editor';
                    })
                    ->guard(function (Document $record, array $data, Workflow $workflow, ?string $state) use (&$seen): bool|string {
                        $seen['guard'] = [$workflow->getName(), $state, $data];

                        return $record->title === 'Ready' ? true : 'Not ready';
                    }),
            ]);
    });

    $editor = $this->user('Eve', 'editor');
    $document = Document::query()->create(['title' => 'Ready']);

    expect($document->canTransition('publish', $this->user('Bob')))->toBeFalse();

    $document->transition('publish', ['note' => 'go'], user: $editor);

    expect($document->status)->toBe('published')
        ->and($seen['authorize'])->toBe('Eve')
        ->and($seen['guard'])->toBe(['doc', 'draft', ['note' => 'go']]);
});

it('authorises transitions with a Gate ability', function (): void {
    $invoice = $this->invoice();

    expect(fn () => $invoice->transition('void', user: $this->user('Ada')))->toThrow(TransitionDenied::class, 'You are not allowed to do this.');

    $invoice->transition('void', user: $this->user('Root', 'admin'));

    expect($invoice->state)->toBe('void');
});

it('runs as the system without a user', function (): void {
    $invoice = $this->invoice();

    $invoice->transition('void');

    expect($invoice->state)->toBe('void')
        ->and($invoice->workflowHistory()->where('event', WorkflowLog::TRANSITIONED)->sole()->causer_id)->toBeNull();
});

it('requires a comment when the transition asks for one', function (): void {
    $invoice = $this->invoice(attributes: ['state' => 'rejected']);
    $ada = $this->user('Ada');

    expect(fn () => $invoice->transition('reopen', user: $ada))->toThrow(TransitionDenied::class, 'Please add a comment.')
        ->and(fn () => $invoice->transition('reopen', comment: '   ', user: $ada))->toThrow(TransitionDenied::class);

    $invoice->transition('reopen', comment: 'Fixed the VAT', user: $ada);

    expect($invoice->state)->toBe('draft');
});

it('notifies the transition recipients in the bell', function (): void {
    $finance = $this->user('Fin', 'finance');
    $ada = $this->user('Ada');
    $invoice = $this->invoice(attributes: ['state' => 'approved', 'number' => 'INV-7']);

    $invoice->transition('pay', ['reference' => 'R1'], user: $ada);

    $notification = $finance->notifications()->sole();

    expect($notification->data['title'])->toBe('INV-7 is now Paid')
        ->and($notification->data['body'])->toBe('Ada: Mark as paid.')
        ->and($notification->data['actions'][0]['url'] ?? null)->toBe('http://localhost/workflows/invoices/'.$invoice->getKey())
        ->and($ada->notifications()->count())->toBe(0);
});

it('rolls everything back when the using() hook fails', function (): void {
    Document::define(fn () => Workflow::make('doc')
        ->stateColumn('status')
        ->states(['draft', 'published'])
        ->initial('draft')
        ->transitions([
            Transition::make('publish')->from('draft')->to('published')->using(function (Document $record): void {
                $record->title = 'Changed';

                throw new RuntimeException('Printer on fire');
            }),
        ]));

    $document = Document::query()->create(['title' => 'Spec']);

    expect(fn () => $document->transition('publish'))->toThrow(RuntimeException::class);

    expect($document->fresh()?->status)->toBe('draft')
        ->and($document->fresh()?->title)->toBe('Spec')
        ->and($document->workflowHistory()->where('event', WorkflowLog::TRANSITIONED)->exists())->toBeFalse();
});

it('applies a transition only once when two stale copies run it', function (): void {
    $ada = $this->user('Ada');
    $invoice = $this->invoice(attributes: ['state' => 'approved']);
    $first = Invoice::query()->findOrFail($invoice->getKey());
    $second = Invoice::query()->findOrFail($invoice->getKey());

    $first->transition('pay', ['reference' => 'A'], user: $ada);

    expect(fn () => $second->transition('pay', ['reference' => 'B'], user: $ada))->toThrow(TransitionDenied::class)
        ->and($invoice->fresh()?->payment_reference)->toBe('A')
        ->and($invoice->workflowHistory()->where('event', WorkflowLog::TRANSITIONED)->count())->toBe(1);
});

it('explains why a transition is not available', function (): void {
    $invoice = $this->invoice();

    expect(Transitions::denial($invoice, 'pay', null)?->reason)->toBe(TransitionDenied::INVALID_STATE)
        ->and(Transitions::denial($invoice, 'void', $this->user())?->reason)->toBe(TransitionDenied::UNAUTHORIZED)
        ->and(Transitions::denial($invoice, 'submit', $this->user()))->toBeNull();
});

it('filters records by state with the scope', function (): void {
    $this->invoice();
    $this->invoice(attributes: ['state' => 'approved']);
    $this->invoice(attributes: ['state' => 'paid']);

    expect(Invoice::query()->whereWorkflowState(['approved', 'paid'])->count())->toBe(2)
        ->and(Invoice::query()->whereWorkflowState('draft')->count())->toBe(1);
});
