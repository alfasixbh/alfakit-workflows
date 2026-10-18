<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitWorkflows\Actions;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Actions\ActionGroup;
use AlfaSix\Alfakit\Actions\Exceptions\Halt;
use AlfaSix\Alfakit\Forms\Components\Select;
use AlfaSix\Alfakit\Forms\Components\Textarea;
use AlfaSix\Alfakit\Notifications\Notification;
use AlfaSix\Alfakit\Schemas\Components\Component;
use AlfaSix\Alfakit\Support\Enums\Width;
use AlfaSix\AlfakitWorkflows\Exceptions\TransitionDenied;
use AlfaSix\AlfakitWorkflows\Models\Approval;
use AlfaSix\AlfakitWorkflows\Models\ApprovalRequest;
use AlfaSix\AlfakitWorkflows\Schemas\Components\WorkflowDiagram;
use AlfaSix\AlfakitWorkflows\Support\Approvals;
use AlfaSix\AlfakitWorkflows\Support\Context;
use AlfaSix\AlfakitWorkflows\Support\Transitions;
use AlfaSix\AlfakitWorkflows\Support\Users;
use AlfaSix\AlfakitWorkflows\Support\Workflows;
use AlfaSix\AlfakitWorkflows\Transition;
use AlfaSix\AlfakitWorkflows\Workflow;
use AlfaSix\AlfakitWorkflows\WorkflowsPlugin;
use Closure;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Throwable;

/**
 * The record's workflow as AlfaKit actions: one per transition (with its form and
 * comment), plus Approve, Reject, Delegate, Reassign and Withdraw for pending approvals,
 * and "View workflow" (the diagram). Only what the current user may do in the record's
 * current state is shown, and everything is checked again on the server when it runs.
 *
 * ```php
 * // Resource view / edit page header (inline buttons):
 * protected function getHeaderActions(): array
 * {
 *     return [WorkflowActions::make()->dropdown(false)];
 * }
 *
 * // Table rows (a dropdown):
 * ->recordActions([WorkflowActions::make()])
 * ```
 *
 * The workflow is the one of the record's model; pass a model class to `make()` when the
 * actions are built where no record or model is known.
 */
class WorkflowActions extends ActionGroup
{
    public const TRANSITION_PREFIX = 'workflow_';

    public const APPROVE = 'workflow-approve';

    public const REJECT = 'workflow-reject';

    public const DELEGATE = 'workflow-delegate';

    public const REASSIGN = 'workflow-reassign';

    public const WITHDRAW = 'workflow-withdraw';

    public const DIAGRAM = 'workflow-diagram';

    public const COMMENT_FIELD = 'workflow_comment';

    /** @var class-string<Model>|null */
    protected ?string $workflowModel = null;

    protected bool $isBuilt = false;

    protected mixed $workflowContext = null;

    protected mixed $workflowRecordKey = null;

    protected ?Component $workflowSchemaComponent = null;

    protected ?Component $workflowFieldComponent = null;

    protected bool|Closure $hasApprovalActions = true;

    protected bool|Closure $hasDiagramAction = true;

    /** @var array<int, string>|null */
    protected ?array $onlyTransitions = null;

    /** @var array<int, string> */
    protected array $exceptTransitions = [];

    /**
     * @param  class-string<Model>|array<int, mixed>|null  $model  The model whose workflow is used (default: the record's)
     */
    public static function make(array|string|null $model = null): static
    {
        if (is_array($model) && $model !== []) {
            throw new InvalidArgumentException('WorkflowActions builds its own actions: pass a model class or nothing.');
        }

        $static = new static([]);

        if (is_string($model)) {
            if (! Workflows::uses($model)) {
                throw new InvalidArgumentException("The model [{$model}] does not use the HasWorkflow trait.");
            }

            $static->workflowModel = $model;
        }

        $static->configure();

        return $static;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('alfakit-workflows::workflows.actions.group'));
        $this->icon('workflow');
        $this->button();
    }

    // ---------------------------------------------------------------- Options

    /**
     * Include Approve / Reject / Delegate / Reassign / Withdraw (default).
     */
    public function approvalActions(bool|Closure $condition = true): static
    {
        $this->hasApprovalActions = $condition;

        return $this;
    }

    /**
     * Include "View workflow" (default).
     */
    public function diagramAction(bool|Closure $condition = true): static
    {
        $this->hasDiagramAction = $condition;

        return $this;
    }

    /**
     * Only these transitions.
     *
     * @param  array<int, string>|null  $transitions
     */
    public function only(?array $transitions): static
    {
        $this->onlyTransitions = $transitions;

        return $this;
    }

    /**
     * Every transition but these.
     *
     * @param  array<int, string>  $transitions
     */
    public function except(array $transitions): static
    {
        $this->exceptTransitions = $transitions;

        return $this;
    }

    // ---------------------------------------------------------------- Lazy building

    public function getActions(): array
    {
        $this->build();

        return parent::getActions();
    }

    public function getFlatActions(): array
    {
        $this->build();

        return parent::getFlatActions();
    }

    protected function getVisibleActions(): array
    {
        $this->build();

        $actions = parent::getVisibleActions();
        usort($actions, static fn (Action|ActionGroup $a, Action|ActionGroup $b): int => ($a instanceof Action ? $a->getSort() : 0) <=> ($b instanceof Action ? $b->getSort() : 0));

        return $actions;
    }

    public function record(Model|array|Closure|null $record): static
    {
        parent::record($record);
        $this->build();

        return $this;
    }

    public function recordKey(mixed $key): static
    {
        $this->workflowRecordKey = $key;

        return parent::recordKey($key);
    }

    public function context(mixed $context): static
    {
        $this->workflowContext = $context;

        return parent::context($context);
    }

    public function schemaComponent(?Component $component): static
    {
        $this->workflowSchemaComponent = $component;

        return parent::schemaComponent($component);
    }

    public function fieldComponent(?Component $component): static
    {
        $this->workflowFieldComponent = $component;

        return parent::fieldComponent($component);
    }

    /**
     * @return class-string<Model>|null
     */
    public function getWorkflowModel(): ?string
    {
        if ($this->workflowModel !== null) {
            return $this->workflowModel;
        }

        $record = $this->evaluate($this->record);

        if ($record instanceof Model && Workflows::uses($record)) {
            return $record::class;
        }

        $context = $this->workflowContext;

        if (is_object($context) && method_exists($context, 'getModel')) {
            try {
                $model = $context->getModel();
            } catch (Throwable) {
                $model = null;
            }

            if (is_string($model) && Workflows::uses($model)) {
                return $model;
            }
        }

        return null;
    }

    protected function build(): void
    {
        if ($this->isBuilt) {
            return;
        }

        $model = $this->getWorkflowModel();

        if ($model === null) {
            return;
        }

        $this->isBuilt = true;
        $workflow = Workflows::for($model);
        $actions = [];

        foreach ($workflow->getTransitions() as $name => $transition) {
            if (($this->onlyTransitions !== null && ! in_array($name, $this->onlyTransitions, true)) || in_array($name, $this->exceptTransitions, true)) {
                continue;
            }

            $actions[] = $this->makeTransitionAction($workflow, $transition);
        }

        if ($this->evaluate($this->hasApprovalActions)) {
            array_push($actions, $this->makeApproveAction(), $this->makeRejectAction(), $this->makeDelegateAction(), $this->makeReassignAction(), $this->makeWithdrawAction());
        }

        if ($this->evaluate($this->hasDiagramAction)) {
            $actions[] = $this->makeDiagramAction($model);
        }

        $this->actions = $actions;
        $record = $this->evaluate($this->record);

        foreach ($actions as $action) {
            $action->parentGroup($this);
            $action->context($this->workflowContext);

            if ($record !== null) {
                $action->record($record);
            }

            if ($this->workflowRecordKey !== null) {
                $action->recordKey($this->workflowRecordKey);
            }

            if ($this->workflowSchemaComponent !== null) {
                $action->schemaComponent($this->workflowSchemaComponent);
            }

            if ($this->workflowFieldComponent !== null) {
                $action->fieldComponent($this->workflowFieldComponent);
            }
        }
    }

    // ---------------------------------------------------------------- Transitions

    protected function makeTransitionAction(Workflow $workflow, Transition $transition): Action
    {
        $name = $transition->getName();

        return Action::make(self::TRANSITION_PREFIX.$name)
            ->label($transition->getLabel())
            ->icon($transition->getIcon() ?? ($transition->requiresApprovalSteps() ? 'send' : 'arrow-right-left'))
            ->color($transition->getColor() ?? 'primary')
            ->sort($transition->getSort())
            ->visible(static fn (mixed $record): bool => $record instanceof Model && Workflows::uses($record) && Transitions::denial($record, $name, Context::user()) === null)
            ->requiresConfirmation(static fn (mixed $record): bool => ! $transition->hasComment() && $transition->getFormComponents($record instanceof Model ? $record : null) === [])
            ->modalHeading($transition->getLabel())
            ->modalDescription(static function (mixed $record) use ($transition, $workflow): string {
                $record = $record instanceof Model ? $record : null;
                $description = $transition->getDescription($record);

                if ($description !== null) {
                    return $description;
                }

                if ($transition->requiresApprovalSteps()) {
                    return (string) __('alfakit-workflows::workflows.actions.transition.approval_description', [
                        'steps' => implode(' → ', array_map(static fn ($step): string => $step->getLabel(), $transition->getApprovalSteps())),
                        'state' => (string) $workflow->getStateLabel($transition->getTo()),
                    ]);
                }

                return (string) __('alfakit-workflows::workflows.actions.transition.description', [
                    'state' => (string) $workflow->getStateLabel($transition->getTo()),
                ]);
            })
            ->modalSubmitActionLabel($transition->requiresApprovalSteps() ? __('alfakit-workflows::workflows.actions.transition.submit_for_approval') : $transition->getLabel())
            ->form(static function (mixed $record) use ($transition): ?array {
                $record = $record instanceof Model ? $record : null;
                $components = $transition->getFormComponents($record);

                if ($transition->hasComment()) {
                    $components[] = self::commentField($transition->isCommentRequired());
                }

                return $components === [] ? null : $components;
            })
            ->action(static function (array $data, mixed $record) use ($transition, $workflow, $name): void {
                abort_unless($record instanceof Model, 404);

                $comment = $data[self::COMMENT_FIELD] ?? null;
                unset($data[self::COMMENT_FIELD]);

                $request = self::attempt(static fn (): ?ApprovalRequest => Transitions::apply($record, $name, $data, is_string($comment) ? $comment : null));

                Notification::make()
                    ->success()
                    ->title($request !== null
                        ? (string) __('alfakit-workflows::workflows.actions.transition.requested')
                        : ($transition->getSuccessNotificationTitle() ?? (string) __('alfakit-workflows::workflows.actions.transition.done', ['state' => (string) $workflow->getStateLabel($transition->getTo())])))
                    ->send();
            });
    }

    // ---------------------------------------------------------------- Approvals

    protected function makeApproveAction(): Action
    {
        return Action::make(self::APPROVE)
            ->label(__('alfakit-workflows::workflows.actions.approve.label'))
            ->icon('check')
            ->color('success')
            ->sort(1000)
            ->visible(static fn (mixed $record): bool => self::assignment($record) !== null)
            ->modalHeading(__('alfakit-workflows::workflows.actions.approve.heading'))
            ->modalDescription(static fn (mixed $record): ?string => self::requestSummary($record))
            ->modalSubmitActionLabel(__('alfakit-workflows::workflows.actions.approve.submit'))
            ->form(static function (mixed $record): array {
                $step = self::pendingRequest($record)?->getCurrentStepDefinition();

                return [self::commentField((bool) $step?->isApprovalCommentRequired())];
            })
            ->action(static function (array $data, mixed $record): void {
                $approval = self::assignment($record);
                abort_if($approval === null, 403);

                self::attempt(static fn (): Approval => Approvals::approve($approval, Context::user(), self::comment($data)));

                Notification::make()->success()->title(__('alfakit-workflows::workflows.actions.approve.done'))->send();
            });
    }

    protected function makeRejectAction(): Action
    {
        return Action::make(self::REJECT)
            ->label(__('alfakit-workflows::workflows.actions.reject.label'))
            ->icon('x')
            ->color('danger')
            ->sort(1001)
            ->visible(static fn (mixed $record): bool => self::assignment($record) !== null)
            ->modalHeading(__('alfakit-workflows::workflows.actions.reject.heading'))
            ->modalDescription(static fn (mixed $record): ?string => self::requestSummary($record))
            ->modalSubmitActionLabel(__('alfakit-workflows::workflows.actions.reject.submit'))
            ->form(static function (mixed $record): array {
                $step = self::pendingRequest($record)?->getCurrentStepDefinition();

                return [self::commentField($step === null || $step->isRejectionCommentRequired())];
            })
            ->action(static function (array $data, mixed $record): void {
                $approval = self::assignment($record);
                abort_if($approval === null, 403);

                self::attempt(static fn (): Approval => Approvals::reject($approval, Context::user(), self::comment($data)));

                Notification::make()->success()->title(__('alfakit-workflows::workflows.actions.reject.done'))->send();
            });
    }

    protected function makeDelegateAction(): Action
    {
        return Action::make(self::DELEGATE)
            ->label(__('alfakit-workflows::workflows.actions.delegate.label'))
            ->icon('forward')
            ->color('gray')
            ->sort(1002)
            ->visible(static function (mixed $record): bool {
                $approval = self::assignment($record);

                return $approval !== null && (bool) self::pendingRequest($record)?->getCurrentStepDefinition()?->isDelegable();
            })
            ->modalHeading(__('alfakit-workflows::workflows.actions.delegate.heading'))
            ->modalDescription(__('alfakit-workflows::workflows.actions.delegate.description'))
            ->modalSubmitActionLabel(__('alfakit-workflows::workflows.actions.delegate.submit'))
            ->form(static fn (mixed $record): array => [
                self::userSelect('approver', $record),
                self::commentField(false),
            ])
            ->action(static function (array $data, mixed $record): void {
                $approval = self::assignment($record);
                abort_if($approval === null, 403);

                $to = $data['approver'] ?? null;
                abort_unless(is_scalar($to), 422);

                self::attempt(static fn (): Approval => Approvals::delegate($approval, $to, Context::user(), self::comment($data)));

                Notification::make()->success()->title(__('alfakit-workflows::workflows.actions.delegate.done'))->send();
            });
    }

    protected function makeReassignAction(): Action
    {
        return Action::make(self::REASSIGN)
            ->label(__('alfakit-workflows::workflows.actions.reassign.label'))
            ->icon('user-round-cog')
            ->color('gray')
            ->sort(1003)
            ->visible(static function (mixed $record): bool {
                return $record instanceof Model
                    && self::pendingRequest($record) !== null
                    && WorkflowsPlugin::get()->canReassign($record, Context::user());
            })
            ->modalHeading(__('alfakit-workflows::workflows.actions.reassign.heading'))
            ->modalSubmitActionLabel(__('alfakit-workflows::workflows.actions.reassign.submit'))
            ->form(static fn (mixed $record): array => [
                Select::make('approval')
                    ->label(__('alfakit-workflows::workflows.actions.reassign.approval'))
                    ->options(static function () use ($record): array {
                        $request = self::pendingRequest($record);

                        if ($request === null) {
                            return [];
                        }

                        $options = [];

                        foreach ($request->approvals()->pending()->where('step', $request->current_step)->get() as $approval) {
                            $options[(string) $approval->getKey()] = Users::name(Users::find($approval->approver_type, $approval->approver_id));
                        }

                        return $options;
                    })
                    ->required(),
                self::userSelect('approver', $record, reassigning: true),
                self::commentField(false),
            ])
            ->action(static function (array $data, mixed $record): void {
                $request = self::pendingRequest($record);
                abort_if($request === null || ! $record instanceof Model || ! WorkflowsPlugin::get()->canReassign($record, Context::user()), 403);

                $approval = $request->approvals()->pending()->whereKey($data['approval'] ?? null)->first();
                abort_if($approval === null, 422);

                $to = $data['approver'] ?? null;
                abort_unless(is_scalar($to), 422);

                self::attempt(static fn (): Approval => Approvals::delegate($approval, $to, Context::user(), self::comment($data), reassign: true));

                Notification::make()->success()->title(__('alfakit-workflows::workflows.actions.reassign.done'))->send();
            });
    }

    protected function makeWithdrawAction(): Action
    {
        return Action::make(self::WITHDRAW)
            ->label(__('alfakit-workflows::workflows.actions.withdraw.label'))
            ->icon('undo-2')
            ->color('gray')
            ->sort(1004)
            ->visible(static function (mixed $record): bool {
                $request = self::pendingRequest($record);
                $user = Context::user();

                return $request !== null && $record instanceof Model && ($request->isRequestedBy($user) || WorkflowsPlugin::get()->canReassign($record, $user));
            })
            ->requiresConfirmation()
            ->modalHeading(__('alfakit-workflows::workflows.actions.withdraw.heading'))
            ->modalDescription(__('alfakit-workflows::workflows.actions.withdraw.description'))
            ->modalSubmitActionLabel(__('alfakit-workflows::workflows.actions.withdraw.submit'))
            ->form([self::commentField(false)])
            ->action(static function (array $data, mixed $record): void {
                $request = self::pendingRequest($record);
                abort_if($request === null, 403);

                self::attempt(static fn (): ApprovalRequest => Approvals::cancel($request, Context::user(), self::comment($data)));

                Notification::make()->success()->title(__('alfakit-workflows::workflows.actions.withdraw.done'))->send();
            });
    }

    /**
     * @param  class-string<Model>  $model
     */
    protected function makeDiagramAction(string $model): Action
    {
        return Action::make(self::DIAGRAM)
            ->label(__('alfakit-workflows::workflows.actions.diagram.label'))
            ->icon('workflow')
            ->color('gray')
            ->sort(1100)
            ->visible(static fn (mixed $record): bool => $record instanceof Model && Workflows::uses($record))
            ->modalHeading(static fn (): string => Workflows::for($model)->getLabel())
            ->modalWidth(Width::FourExtraLarge)
            ->modalContent(static fn (mixed $record): array => [
                WorkflowDiagram::make()->workflow($model)->subject($record instanceof Model ? $record : null),
            ])
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('alfakit-workflows::workflows.actions.diagram.close'));
    }

    // ---------------------------------------------------------------- Helpers

    public static function pendingRequest(mixed $record): ?ApprovalRequest
    {
        if (! $record instanceof Model || ! $record->exists || ! Workflows::uses($record)) {
            return null;
        }

        $request = Approvals::pendingFor($record);

        return $request !== null && Context::isCurrentTenant($request->tenant_type, $request->tenant_id) ? $request : null;
    }

    public static function assignment(mixed $record): ?Approval
    {
        $request = self::pendingRequest($record);

        return $request === null ? null : Approvals::assignmentFor($request, Context::user());
    }

    protected static function requestSummary(mixed $record): ?string
    {
        $request = self::pendingRequest($record);
        $transition = $request?->getTransitionDefinition();

        if ($request === null || $transition === null) {
            return null;
        }

        $summary = (string) __('alfakit-workflows::workflows.actions.approve.summary', [
            'name' => Users::name(Users::find($request->requester_type, $request->requester_id)),
            'transition' => $transition->getLabel(),
            'step' => (string) $request->getCurrentStepDefinition()?->getLabel(),
        ]);

        return $request->comment === null ? $summary : $summary.' “'.e($request->comment).'”';
    }

    public static function commentField(bool $required): Textarea
    {
        return Textarea::make(self::COMMENT_FIELD)
            ->label(__('alfakit-workflows::workflows.fields.comment'))
            ->placeholder($required ? __('alfakit-workflows::workflows.fields.comment_required') : __('alfakit-workflows::workflows.fields.comment_optional'))
            ->rows(3)
            ->maxLength(5000)
            ->required($required);
    }

    protected static function userSelect(string $name, mixed $record, bool $reassigning = false): Select
    {
        $exclude = static function () use ($record, $reassigning): array {
            $request = self::pendingRequest($record);
            $keys = [];

            if (! $reassigning && ($user = Context::user()) !== null) {
                $keys[] = (string) $user->getKey();
            }

            foreach ($request?->approvals()->pending()->where('step', $request->current_step)->get() ?? [] as $approval) {
                $keys[] = $approval->approver_id;
            }

            if ($request !== null && $request->requester_id !== null && $record instanceof Model && ! Workflows::for($record)->allowsSelfApproval()) {
                $keys[] = $request->requester_id;
            }

            return array_values(array_unique($keys));
        };

        return Select::make($name)
            ->label(__('alfakit-workflows::workflows.fields.approver'))
            ->searchable()
            ->getSearchResultsUsing(static fn (?string $search): array => Users::search((string) $search, self::tenantOf($record), $exclude()))
            ->getOptionLabelUsing(static fn (mixed $value): ?string => is_scalar($value) ? Users::name(Users::findCandidate($value, self::tenantOf($record))) : null)
            ->required();
    }

    protected static function tenantOf(mixed $record): ?Model
    {
        $request = self::pendingRequest($record);

        return $request === null ? Context::tenant() : Context::findTenant($request->tenant_type, $request->tenant_id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected static function comment(array $data): ?string
    {
        $comment = $data[self::COMMENT_FIELD] ?? null;

        return is_string($comment) ? $comment : null;
    }

    /**
     * Run a workflow call; a refusal becomes a danger notification and stops the action.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    protected static function attempt(Closure $callback): mixed
    {
        try {
            return $callback();
        } catch (TransitionDenied $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();

            throw new Halt;
        }
    }
}
