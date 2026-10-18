<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitWorkflows\Concerns;

use AlfaSix\AlfakitWorkflows\Exceptions\TransitionDenied;
use AlfaSix\AlfakitWorkflows\Models\ApprovalRequest;
use AlfaSix\AlfakitWorkflows\Models\WorkflowLog;
use AlfaSix\AlfakitWorkflows\State;
use AlfaSix\AlfakitWorkflows\Support\Approvals;
use AlfaSix\AlfakitWorkflows\Support\History;
use AlfaSix\AlfakitWorkflows\Support\Transitions;
use AlfaSix\AlfakitWorkflows\Support\Workflows;
use AlfaSix\AlfakitWorkflows\Transition;
use AlfaSix\AlfakitWorkflows\Workflow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Binds a workflow to the model. Define it in `workflow()`:
 *
 * ```php
 * class Invoice extends Model
 * {
 *     use HasWorkflow;
 *
 *     public static function workflow(): Workflow
 *     {
 *         return Workflow::make('invoice')->states([...])->transitions([...])->initial('draft');
 *     }
 * }
 *
 * $invoice->transition('submit', comment: 'Ready for review');
 * ```
 *
 * New records start in the initial state. With `guardStateColumn()` (default) saving a
 * changed state outside a transition throws.
 *
 * @mixin Model
 */
trait HasWorkflow
{
    abstract public static function workflow(): Workflow;

    public static function bootHasWorkflow(): void
    {
        static::creating(static function (Model $model): void {
            $workflow = Workflows::for($model);

            if ($workflow->getStateOf($model) === null) {
                $model->setAttribute($workflow->getStateColumn(), $workflow->getInitialState());
            }
        });

        static::created(static function (Model $model): void {
            $workflow = Workflows::for($model);

            History::log($workflow, $model, WorkflowLog::CREATED, \AlfaSix\AlfakitWorkflows\Support\Context::user(), [
                'to_state' => $workflow->getStateOf($model),
            ]);
        });

        static::updating(static function (Model $model): void {
            $workflow = Workflows::for($model);

            if ($workflow->guardsStateColumn() && $model->isDirty($workflow->getStateColumn()) && ! Workflows::isApplying()) {
                throw TransitionDenied::because(TransitionDenied::INVALID_STATE, (string) __('alfakit-workflows::workflows.errors.direct_change', [
                    'column' => $workflow->getStateColumn(),
                ]));
            }
        });
    }

    public function getWorkflow(): Workflow
    {
        return Workflows::for($this);
    }

    public function getWorkflowState(): ?string
    {
        return $this->getWorkflow()->getStateOf($this);
    }

    public function getWorkflowStateDefinition(): ?State
    {
        return $this->getWorkflow()->getState($this->getWorkflowState());
    }

    /**
     * Run a transition (in a transaction, on the locked row). Without `$user`, the panel's
     * signed-in user runs it; without one either, it runs as the system (authorisation
     * skipped, guards still checked).
     *
     * @param  array<string, mixed>  $data
     *
     * @throws TransitionDenied
     */
    public function transition(string $name, array $data = [], ?string $comment = null, ?Model $user = null): static
    {
        Transitions::apply($this, $name, $data, $comment, $user);

        return $this;
    }

    public function canTransition(string $name, ?Model $user = null): bool
    {
        return Transitions::denial($this, $name, $user ?? \AlfaSix\AlfakitWorkflows\Support\Context::user()) === null;
    }

    /**
     * @return array<string, Transition>
     */
    public function getAvailableTransitions(?Model $user = null): array
    {
        return Transitions::available($this, $user ?? \AlfaSix\AlfakitWorkflows\Support\Context::user());
    }

    public function getPendingApprovalRequest(): ?ApprovalRequest
    {
        return $this->exists ? Approvals::pendingFor($this) : null;
    }

    public function isAwaitingApproval(): bool
    {
        return $this->getPendingApprovalRequest() !== null;
    }

    /**
     * The record's audit trail.
     *
     * @return Builder<WorkflowLog>
     */
    public function workflowHistory(): Builder
    {
        return WorkflowLog::query()->forSubject($this);
    }

    /**
     * The record's approval requests.
     *
     * @return Builder<ApprovalRequest>
     */
    public function approvalRequests(): Builder
    {
        return ApprovalRequest::query()->forSubject($this);
    }

    /**
     * @param  Builder<static>  $query
     * @param  string|array<int, string>  $states
     * @return Builder<static>
     */
    public function scopeWhereWorkflowState(Builder $query, string|array $states): Builder
    {
        return $query->whereIn($query->qualifyColumn(Workflows::for(static::class)->getStateColumn()), (array) $states);
    }
}
