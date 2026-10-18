<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitWorkflows;

use AlfaSix\Alfakit\Schemas\Components\Component;
use AlfaSix\Alfakit\Support\Concerns\EvaluatesClosures;
use Closure;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A move from one or more states to another. It can be guarded (business rules),
 * authorised (who), ask for a form and a comment, notify users, and wait for approval
 * steps before it is applied.
 *
 * ```php
 * Transition::make('submit')
 *     ->from('draft')
 *     ->to('approved')
 *     ->guard(fn (Invoice $record): bool => $record->total > 0, 'Add a line first.')
 *     ->requiresApproval([ApprovalStep::make('manager')->role('manager')])
 *     ->pendingState('submitted')
 *     ->rejectedState('rejected')
 * ```
 */
class Transition
{
    use EvaluatesClosures;

    /** @var array<int, string> */
    protected array $from = [];

    protected bool $isFromAny = false;

    protected ?string $to = null;

    protected string|Closure|null $label = null;

    protected string|Closure|null $icon = null;

    protected string|Closure|null $color = null;

    protected string|Closure|null $description = null;

    protected string|Closure|null $successMessage = null;

    /** @var array<int, array{0: Closure, 1: string|null}> */
    protected array $guards = [];

    protected string|bool|Closure|null $authorization = null;

    /** @var array<Component>|Closure|null */
    protected array|Closure|null $form = null;

    protected bool|Closure $hasComment = false;

    protected bool|Closure $isCommentRequired = false;

    /** @var array<int, mixed> */
    protected array $notify = [];

    /** @var array<int, ApprovalStep> */
    protected array $approvalSteps = [];

    protected ?string $pendingState = null;

    protected ?string $rejectedState = null;

    protected ?Closure $using = null;

    protected ?Closure $after = null;

    protected int $sort = 0;

    final public function __construct(protected string $name) {}

    public static function make(string $name): static
    {
        return new static($name);
    }

    public function getName(): string
    {
        return $this->name;
    }

    protected function getEvaluationIdentifier(): string
    {
        return 'transition';
    }

    // ---------------------------------------------------------------- States

    /**
     * @param  string|array<int, string>  $states
     */
    public function from(string|array $states): static
    {
        $states = array_values(array_unique((array) $states));
        $this->isFromAny = in_array('*', $states, true);
        $this->from = array_values(array_filter($states, static fn (string $state): bool => $state !== '*'));

        return $this;
    }

    /**
     * From every state that is not final.
     */
    public function fromAny(): static
    {
        return $this->from('*');
    }

    /**
     * @return array<int, string>
     */
    public function getFrom(): array
    {
        return $this->from;
    }

    public function isFromAny(): bool
    {
        return $this->isFromAny;
    }

    public function to(string $state): static
    {
        $this->to = $state;

        return $this;
    }

    public function getTo(): ?string
    {
        return $this->to;
    }

    /**
     * Whether the transition leaves this state (the workflow's final states excepted).
     */
    public function allowsFrom(?string $state, Workflow $workflow): bool
    {
        if ($state === null || ! $workflow->hasState($state)) {
            return false;
        }

        if ($this->isFromAny) {
            return ! $workflow->getState($state)?->isFinal() && $state !== $this->to;
        }

        return in_array($state, $this->from, true);
    }

    // ---------------------------------------------------------------- Appearance

    public function label(string|Closure|null $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getLabel(): string
    {
        $label = $this->evaluate($this->label);

        return is_string($label) && $label !== '' ? $label : Str::headline($this->name);
    }

    public function icon(string|Closure|null $icon): static
    {
        $this->icon = $icon;

        return $this;
    }

    public function getIcon(): ?string
    {
        $icon = $this->evaluate($this->icon);

        return is_string($icon) && $icon !== '' ? $icon : null;
    }

    public function color(string|Closure|null $color): static
    {
        $this->color = $color;

        return $this;
    }

    public function getColor(): ?string
    {
        $color = $this->evaluate($this->color);

        return is_string($color) && $color !== '' ? $color : null;
    }

    /**
     * Shown in the action's modal.
     */
    public function description(string|Closure|null $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getDescription(?Model $record = null): ?string
    {
        $description = $this->evaluate($this->description, ['record' => $record], $record === null ? [] : [Model::class => $record, $record::class => $record]);

        return is_string($description) && $description !== '' ? $description : null;
    }

    /**
     * The success notification of the action (defaults to "Moved to :state").
     */
    public function successNotificationTitle(string|Closure|null $message): static
    {
        $this->successMessage = $message;

        return $this;
    }

    public function getSuccessNotificationTitle(): ?string
    {
        $message = $this->evaluate($this->successMessage);

        return is_string($message) && $message !== '' ? $message : null;
    }

    public function sort(int $sort): static
    {
        $this->sort = $sort;

        return $this;
    }

    public function getSort(): int
    {
        return $this->sort;
    }

    // ---------------------------------------------------------------- Rules

    /**
     * A business rule checked when the actions are shown and again, on the locked row,
     * when the transition runs: `fn (Invoice $record, ?User $user): bool|string`. Return
     * `false` (or a message string) to refuse. Call it several times to add more guards.
     */
    public function guard(Closure $callback, ?string $message = null): static
    {
        $this->guards[] = [$callback, $message];

        return $this;
    }

    /**
     * Who may run it: a Gate ability checked against the record (`'approve'`), a boolean,
     * or `fn (Invoice $record, User $user): bool|Response`. Without it, a `transition`
     * policy method (`transition($user, $record, string $transition)`) is asked, else the
     * record's `update` ability.
     */
    public function authorize(string|bool|Closure|null $ability): static
    {
        $this->authorization = $ability;

        return $this;
    }

    public function getAuthorization(): string|bool|Closure|null
    {
        return $this->authorization;
    }

    /**
     * The first guard that refuses, as its message (a generic one when it has none).
     * Null when every guard passes.
     *
     * @param  array<string, mixed>  $data
     */
    public function checkGuards(Model $record, ?Model $user, Workflow $workflow, array $data = []): ?string
    {
        foreach ($this->guards as [$guard, $message]) {
            $typed = [Model::class => $record, $record::class => $record, Workflow::class => $workflow];

            if ($user !== null && ! $user instanceof $record) {
                $typed[$user::class] = $user;
            }

            $result = $this->evaluate($guard, [
                'record' => $record,
                'user' => $user,
                'workflow' => $workflow,
                'data' => $data,
                'state' => $workflow->getStateOf($record),
            ], $typed);

            if ($result instanceof Response) {
                if ($result->denied()) {
                    return $result->message() ?? $message ?? (string) __('alfakit-workflows::workflows.errors.guard');
                }

                continue;
            }

            if (is_string($result) && $result !== '') {
                return $result;
            }

            if ($result === false) {
                return $message ?? (string) __('alfakit-workflows::workflows.errors.guard');
            }
        }

        return null;
    }

    // ---------------------------------------------------------------- Form

    /**
     * Fields shown in the action's modal; their validated data is passed to `using()` and
     * stored in the history.
     *
     * @param  array<Component>|Closure|null  $components
     */
    public function form(array|Closure|null $components): static
    {
        $this->form = $components;

        return $this;
    }

    /**
     * @return array<Component>
     */
    public function getFormComponents(?Model $record = null): array
    {
        $components = $this->evaluate($this->form, ['record' => $record], $record === null ? [] : [Model::class => $record, $record::class => $record]);

        return is_array($components) ? array_values(array_filter($components, static fn (mixed $component): bool => $component instanceof Component)) : [];
    }

    public function hasForm(): bool
    {
        return $this->form !== null;
    }

    /**
     * Ask for an optional comment, stored in the history.
     */
    public function withComment(bool|Closure $condition = true): static
    {
        $this->hasComment = $condition;

        return $this;
    }

    /**
     * Ask for a comment and refuse the transition without one.
     */
    public function requiresComment(bool|Closure $condition = true): static
    {
        $this->isCommentRequired = $condition;

        return $this;
    }

    public function hasComment(): bool
    {
        return $this->isCommentRequired() || (bool) $this->evaluate($this->hasComment);
    }

    public function isCommentRequired(): bool
    {
        return (bool) $this->evaluate($this->isCommentRequired);
    }

    // ---------------------------------------------------------------- Effects

    /**
     * Notify users once the transition is applied: users, user collections, or
     * `fn (Invoice $record, ?User $user): iterable|Model|null`.
     *
     * @param  Model|iterable<Model>|Closure  $recipients
     */
    public function notify(Model|iterable|Closure $recipients): static
    {
        $this->notify[] = $recipients;

        return $this;
    }

    /**
     * @return array<int, mixed>
     */
    public function getNotify(): array
    {
        return $this->notify;
    }

    /**
     * Change the record as part of the transition, inside its transaction, before the
     * new state is saved: `fn (Invoice $record, array $data) => $record->paid_at = now()`.
     */
    public function using(?Closure $callback): static
    {
        $this->using = $callback;

        return $this;
    }

    public function getUsing(): ?Closure
    {
        return $this->using;
    }

    /**
     * Run after the transaction committed: `fn (Invoice $record, array $data, ?User $user)`.
     */
    public function after(?Closure $callback): static
    {
        $this->after = $callback;

        return $this;
    }

    public function getAfter(): ?Closure
    {
        return $this->after;
    }

    // ---------------------------------------------------------------- Approval

    /**
     * Steps that must approve before the record reaches `to()`, in order. Each step can
     * be a single approver, any one of a group or all of a group.
     *
     * @param  ApprovalStep|array<int, ApprovalStep>  $steps
     */
    public function requiresApproval(ApprovalStep|array $steps): static
    {
        $this->approvalSteps = array_values(is_array($steps) ? $steps : [$steps]);

        return $this;
    }

    public function requiresApprovalSteps(): bool
    {
        return $this->approvalSteps !== [];
    }

    /**
     * @return array<int, ApprovalStep>
     */
    public function getApprovalSteps(): array
    {
        return $this->approvalSteps;
    }

    public function getApprovalStep(int $index): ?ApprovalStep
    {
        return $this->approvalSteps[$index] ?? null;
    }

    /**
     * The state the record waits in while approval is pending (it stays in its current
     * state without one).
     */
    public function pendingState(?string $state): static
    {
        $this->pendingState = $state;

        return $this;
    }

    public function getPendingState(): ?string
    {
        return $this->pendingState;
    }

    /**
     * The state a rejection moves the record to. Without one it goes back to where the
     * request started.
     */
    public function rejectedState(?string $state): static
    {
        $this->rejectedState = $state;

        return $this;
    }

    public function getRejectedState(): ?string
    {
        return $this->rejectedState;
    }
}
