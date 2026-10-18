<?php

declare(strict_types=1);

use AlfaSix\AlfakitWorkflows\ApprovalStep;
use AlfaSix\AlfakitWorkflows\Exceptions\InvalidWorkflowDefinition;
use AlfaSix\AlfakitWorkflows\Exceptions\TransitionDenied;
use AlfaSix\AlfakitWorkflows\Models\WorkflowLog;
use AlfaSix\AlfakitWorkflows\State;
use AlfaSix\AlfakitWorkflows\Support\Duration;
use AlfaSix\AlfakitWorkflows\Support\Workflows;
use AlfaSix\AlfakitWorkflows\Tests\Fixtures\Models\Document;
use AlfaSix\AlfakitWorkflows\Tests\Fixtures\Models\Invoice;
use AlfaSix\AlfakitWorkflows\Transition;
use AlfaSix\AlfakitWorkflows\Workflow;

uses(AlfaSix\AlfakitWorkflows\Tests\TestCase::class);

it('validates a correct definition and exposes its states and transitions', function (): void {
    $workflow = Workflows::for(Invoice::class);

    expect($workflow->getName())->toBe('invoice')
        ->and($workflow->getInitialState())->toBe('draft')
        ->and($workflow->getStateOptions())->toBe([
            'draft' => 'Draft', 'submitted' => 'Submitted', 'approved' => 'Approved',
            'rejected' => 'Rejected', 'paid' => 'Paid', 'void' => 'Void',
        ])
        ->and(array_keys($workflow->getTransitionsFrom('draft')))->toBe(['submit', 'void'])
        ->and(array_keys($workflow->getTransitionsFrom('paid')))->toBe([])
        ->and($workflow->getState('approved')?->getColor())->toBe('success')
        ->and(State::make('in_review')->getLabel())->toBe('In Review')
        ->and(Transition::make('send_back')->getLabel())->toBe('Send Back');
});

it('refuses invalid definitions with every problem named', function (Workflow $workflow, string $problem): void {
    expect(fn () => $workflow->validate())->toThrow(InvalidWorkflowDefinition::class, $problem);
})->with([
    'no states' => [fn () => Workflow::make('x')->initial('a'), 'It has no states.'],
    'no initial state' => [fn () => Workflow::make('x')->states(['a']), 'It has no initial state.'],
    'unknown initial' => [fn () => Workflow::make('x')->states(['a'])->initial('b'), 'initial state [b]'],
    'unknown from' => [fn () => Workflow::make('x')->states(['a', 'b'])->initial('a')->transitions([Transition::make('go')->from('z')->to('b')]), 'unknown state [z]'],
    'unknown to' => [fn () => Workflow::make('x')->states(['a'])->initial('a')->transitions([Transition::make('go')->from('a')->to('z')]), 'goes to the unknown state [z]'],
    'missing to' => [fn () => Workflow::make('x')->states(['a'])->initial('a')->transitions([Transition::make('go')->from('a')]), 'has no to() state'],
    'leaves final' => [fn () => Workflow::make('x')->states([State::make('a')->final(), 'b'])->initial('a')->transitions([Transition::make('go')->from('a')->to('b')]), 'leaves the final state [a]'],
    'bad name' => [fn () => Workflow::make('x')->states(['a', 'b'])->initial('a')->transitions([Transition::make('go-now')->from('a')->to('b')]), 'transition name [go-now]'],
    'step without approvers' => [fn () => Workflow::make('x')->states(['a', 'b'])->initial('a')->transitions([Transition::make('go')->from('a')->to('b')->requiresApproval(ApprovalStep::make('boss'))]), 'has no approvers()'],
    'escalation without target' => [fn () => Workflow::make('x')->states(['a', 'b'])->initial('a')->transitions([Transition::make('go')->from('a')->to('b')->requiresApproval(ApprovalStep::make('boss')->role('boss')->escalateAfter('1 day'))]), 'has no escalateTo()'],
    'pending state without approval' => [fn () => Workflow::make('x')->states(['a', 'b'])->initial('a')->transitions([Transition::make('go')->from('a')->to('b')->pendingState('a')]), 'but no approval steps'],
    'unknown pending state' => [fn () => Workflow::make('x')->states(['a', 'b'])->initial('a')->transitions([Transition::make('go')->from('a')->to('b')->requiresApproval(ApprovalStep::make('s')->role('r'))->pendingState('q')]), 'unknown pending state [q]'],
]);

it('refuses duplicate states and transitions', function (): void {
    expect(fn () => Workflow::make('x')->states(['a', 'a']))->toThrow(InvalidWorkflowDefinition::class, 'state [a] twice')
        ->and(fn () => Workflow::make('x')->transitions([Transition::make('go'), Transition::make('go')]))->toThrow(InvalidWorkflowDefinition::class, 'transition [go] twice');
});

it('validates the model workflow when it is first used', function (): void {
    Document::define(fn () => Workflow::make('broken')->states(['a']));

    expect(fn () => Document::query()->create(['title' => 'Broken']))->toThrow(InvalidWorkflowDefinition::class);
});

it('parses durations', function (): void {
    expect(Duration::parse('2 days')?->totalHours)->toEqual(48)
        ->and(Duration::parse('P1DT12H')?->totalHours)->toEqual(36)
        ->and(Duration::parse(new DateInterval('PT30M'))?->totalMinutes)->toEqual(30)
        ->and(Duration::parse(null))->toBeNull()
        ->and(fn () => Duration::parse('soon-ish'))->toThrow(InvalidArgumentException::class);
});

it('starts new records in the initial state and records their creation', function (): void {
    $this->actingAs($ada = $this->user('Ada'));
    $invoice = $this->invoice();
    $document = Document::query()->create(['title' => 'Spec']);

    $log = WorkflowLog::query()->forSubject($invoice)->sole();

    expect($invoice->state)->toBe('draft')
        ->and($invoice->getWorkflowState())->toBe('draft')
        ->and($invoice->getWorkflowStateDefinition()?->getLabel())->toBe('Draft')
        ->and($document->status)->toBe('draft')
        ->and($log->event)->toBe(WorkflowLog::CREATED)
        ->and($log->to_state)->toBe('draft')
        ->and($log->causer_id)->toBe((string) $ada->getKey());
});

it('keeps an explicit state on create', function (): void {
    expect($this->invoice(attributes: ['state' => 'approved'])->state)->toBe('approved');
});

it('refuses changing the state column outside of a transition', function (): void {
    $invoice = $this->invoice();

    expect(fn () => $invoice->update(['state' => 'paid']))->toThrow(TransitionDenied::class)
        ->and($invoice->fresh()?->state)->toBe('draft');

    // Other attributes still save.
    $invoice->refresh()->update(['number' => 'INV-1']);
    expect($invoice->fresh()?->number)->toBe('INV-1');
});

it('allows direct state changes when the workflow does not guard the column', function (): void {
    Document::define(fn () => Workflow::make('loose')->stateColumn('status')->states(['draft', 'published'])->initial('draft')->guardStateColumn(false));

    $document = Document::query()->create(['title' => 'Spec']);
    $document->update(['status' => 'published']);

    expect($document->fresh()?->status)->toBe('published');
});

it('builds diagram edges including approval outcomes', function (): void {
    $edges = collect(Workflows::for(Invoice::class)->getEdges())
        ->map(fn (array $edge): string => $edge['from'].'>'.$edge['to'].':'.$edge['kind'])
        ->all();

    expect($edges)->toContain('draft>submitted:transition', 'submitted>approved:approved', 'submitted>rejected:rejected', 'approved>paid:transition', 'rejected>draft:transition', 'draft>void:transition', 'approved>void:transition')
        ->not->toContain('paid>void:transition', 'void>void:transition');
});
