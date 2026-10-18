<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitWorkflows;

use AlfaSix\Alfakit\Support\Concerns\EvaluatesClosures;
use AlfaSix\AlfakitWorkflows\Exceptions\InvalidWorkflowDefinition;
use BackedEnum;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A state machine for a model: its states, the transitions between them and the state
 * a new record starts in. Bind it to a model with the `HasWorkflow` trait:
 *
 * ```php
 * public static function workflow(): Workflow
 * {
 *     return Workflow::make('invoice')
 *         ->states([
 *             State::make('draft')->color('gray')->icon('file-pen'),
 *             'submitted', 'approved', 'rejected', 'paid',
 *         ])
 *         ->transitions([
 *             Transition::make('submit')->from('draft')->to('submitted'),
 *         ])
 *         ->initial('draft');
 * }
 * ```
 */
class Workflow
{
    use EvaluatesClosures;

    /** Transition names are used in action names, so they are kept simple. */
    public const NAME_PATTERN = '/^[A-Za-z][A-Za-z0-9_]*$/';

    protected string|Closure|null $label = null;

    /** @var array<string, State> */
    protected array $states = [];

    /** @var array<string, Transition> */
    protected array $transitions = [];

    protected ?string $initial = null;

    protected string $stateColumn = 'state';

    protected bool $guardsStateColumn = true;

    protected bool $allowsSelfApproval = false;

    protected bool $isValidated = false;

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
        return 'workflow';
    }

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

    // ---------------------------------------------------------------- States

    /**
     * @param  array<int, State|string>  $states
     */
    public function states(array $states): static
    {
        $this->states = [];
        $this->isValidated = false;

        foreach ($states as $state) {
            $state = is_string($state) ? State::make($state) : $state;

            if (array_key_exists($state->getName(), $this->states)) {
                throw new InvalidWorkflowDefinition("The workflow [{$this->name}] defines the state [{$state->getName()}] twice.");
            }

            $this->states[$state->getName()] = $state;
        }

        return $this;
    }

    /**
     * @return array<string, State>
     */
    public function getStates(): array
    {
        return $this->states;
    }

    public function hasState(?string $name): bool
    {
        return $name !== null && array_key_exists($name, $this->states);
    }

    public function getState(?string $name): ?State
    {
        return $name === null ? null : ($this->states[$name] ?? null);
    }

    public function getStateLabel(?string $name): ?string
    {
        if ($name === null || $name === '') {
            return null;
        }

        return $this->getState($name)?->getLabel() ?? Str::headline($name);
    }

    /**
     * `value => label` for selects and filters.
     *
     * @return array<string, string>
     */
    public function getStateOptions(): array
    {
        return array_map(static fn (State $state): string => $state->getLabel(), $this->states);
    }

    public function initial(string $state): static
    {
        $this->initial = $state;
        $this->isValidated = false;

        return $this;
    }

    public function getInitialState(): ?string
    {
        return $this->initial;
    }

    /**
     * The model attribute holding the state (`state` by default).
     */
    public function stateColumn(string $column): static
    {
        $this->stateColumn = $column;

        return $this;
    }

    public function getStateColumn(): string
    {
        return $this->stateColumn;
    }

    public function getStateOf(Model $record): ?string
    {
        $value = $record->getAttribute($this->stateColumn);

        if ($value instanceof BackedEnum) {
            $value = $value->value;
        }

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    /**
     * Refuse saving a changed state outside of a transition (default). Turn it off for
     * imports or when another package also writes the column.
     */
    public function guardStateColumn(bool $condition = true): static
    {
        $this->guardsStateColumn = $condition;

        return $this;
    }

    public function guardsStateColumn(): bool
    {
        return $this->guardsStateColumn;
    }

    /**
     * Let the user who asked for an approval approve it themselves (off by default).
     */
    public function allowSelfApproval(bool $condition = true): static
    {
        $this->allowsSelfApproval = $condition;

        return $this;
    }

    public function allowsSelfApproval(): bool
    {
        return $this->allowsSelfApproval;
    }

    // ---------------------------------------------------------------- Transitions

    /**
     * @param  array<int, Transition>  $transitions
     */
    public function transitions(array $transitions): static
    {
        $this->transitions = [];
        $this->isValidated = false;

        foreach (array_values($transitions) as $index => $transition) {
            if (array_key_exists($transition->getName(), $this->transitions)) {
                throw new InvalidWorkflowDefinition("The workflow [{$this->name}] defines the transition [{$transition->getName()}] twice.");
            }

            if ($transition->getSort() === 0) {
                $transition->sort($index + 1);
            }

            $this->transitions[$transition->getName()] = $transition;
        }

        return $this;
    }

    /**
     * @return array<string, Transition>
     */
    public function getTransitions(): array
    {
        return $this->transitions;
    }

    public function getTransition(string $name): ?Transition
    {
        return $this->transitions[$name] ?? null;
    }

    /**
     * The transitions that leave this state, in their order.
     *
     * @return array<string, Transition>
     */
    public function getTransitionsFrom(?string $state): array
    {
        $transitions = array_filter($this->transitions, fn (Transition $transition): bool => $transition->allowsFrom($state, $this));

        uasort($transitions, static fn (Transition $a, Transition $b): int => $a->getSort() <=> $b->getSort());

        return $transitions;
    }

    // ---------------------------------------------------------------- Validation

    /**
     * Check the definition once: every referenced state exists, names are valid, approval
     * steps have approvers. Throws `InvalidWorkflowDefinition` with the first problem.
     */
    public function validate(): static
    {
        if ($this->isValidated) {
            return $this;
        }

        $problems = $this->getDefinitionProblems();

        if ($problems !== []) {
            throw new InvalidWorkflowDefinition("The workflow [{$this->name}] is invalid: ".implode(' ', $problems));
        }

        $this->isValidated = true;

        return $this;
    }

    /**
     * Every problem of the definition, as sentences.
     *
     * @return array<int, string>
     */
    public function getDefinitionProblems(): array
    {
        $problems = [];

        if (preg_match('/^[A-Za-z0-9_.:-]+$/', $this->name) !== 1) {
            $problems[] = 'Its name may only contain letters, digits, dots, colons, dashes and underscores.';
        }

        if ($this->states === []) {
            $problems[] = 'It has no states.';
        }

        if ($this->initial === null) {
            $problems[] = 'It has no initial state.';
        } elseif (! $this->hasState($this->initial)) {
            $problems[] = "Its initial state [{$this->initial}] is not one of its states.";
        }

        foreach ($this->states as $state) {
            if (strlen($state->getName()) > 255 || trim($state->getName()) === '') {
                $problems[] = "The state [{$state->getName()}] needs a name of 1 to 255 characters.";
            }
        }

        foreach ($this->transitions as $name => $transition) {
            if (preg_match(self::NAME_PATTERN, $name) !== 1) {
                $problems[] = "The transition name [{$name}] must start with a letter and contain only letters, digits and underscores.";
            }

            if ($transition->getFrom() === [] && ! $transition->isFromAny()) {
                $problems[] = "The transition [{$name}] has no from() state.";
            }

            foreach ($transition->getFrom() as $from) {
                if (! $this->hasState($from)) {
                    $problems[] = "The transition [{$name}] starts from the unknown state [{$from}].";
                } elseif ($this->getState($from)?->isFinal()) {
                    $problems[] = "The transition [{$name}] leaves the final state [{$from}].";
                }
            }

            $to = $transition->getTo();

            if ($to === null) {
                $problems[] = "The transition [{$name}] has no to() state.";
            } elseif (! $this->hasState($to)) {
                $problems[] = "The transition [{$name}] goes to the unknown state [{$to}].";
            }

            foreach (['pending' => $transition->getPendingState(), 'rejected' => $transition->getRejectedState()] as $kind => $state) {
                if ($state !== null && ! $this->hasState($state)) {
                    $problems[] = "The transition [{$name}] has the unknown {$kind} state [{$state}].";
                }

                if ($state !== null && ! $transition->requiresApprovalSteps()) {
                    $problems[] = "The transition [{$name}] has a {$kind} state but no approval steps.";
                }
            }

            $steps = [];

            foreach ($transition->getApprovalSteps() as $step) {
                if (in_array($step->getName(), $steps, true)) {
                    $problems[] = "The transition [{$name}] has the approval step [{$step->getName()}] twice.";
                }

                $steps[] = $step->getName();

                if (! $step->hasApproverSource()) {
                    $problems[] = "The approval step [{$step->getName()}] of [{$name}] has no approvers(), role() or permission().";
                }

                if ($step->getEscalateAfter() !== null && ! $step->hasEscalationTarget()) {
                    $problems[] = "The approval step [{$step->getName()}] of [{$name}] escalates but has no escalateTo().";
                }
            }
        }

        return $problems;
    }

    /**
     * The workflow's arrows for the diagram: kind is `transition`, `approved` or `rejected`;
     * `any` marks the edges of a `fromAny()` transition (one per state it leaves).
     *
     * @return array<int, array{from: string, to: string, label: string, kind: string, transition: string, any: bool}>
     */
    public function getEdges(): array
    {
        $edges = [];

        foreach ($this->transitions as $name => $transition) {
            $to = (string) $transition->getTo();
            $from = $transition->isFromAny()
                ? array_keys(array_filter($this->states, static fn (State $state): bool => ! $state->isFinal() && $state->getName() !== $to))
                : $transition->getFrom();
            $pending = $transition->getPendingState();

            foreach ($from as $source) {
                $edges[] = ['from' => $source, 'to' => $pending ?? $to, 'label' => $transition->getLabel(), 'kind' => 'transition', 'transition' => $name, 'any' => $transition->isFromAny()];

                if ($pending !== null) {
                    $edges[] = ['from' => $pending, 'to' => $to, 'label' => (string) __('alfakit-workflows::workflows.diagram.approved'), 'kind' => 'approved', 'transition' => $name, 'any' => false];
                }

                $rejected = $transition->getRejectedState() ?? ($pending !== null ? $source : null);

                if ($pending !== null && $rejected !== null) {
                    $edges[] = ['from' => $pending, 'to' => $rejected, 'label' => (string) __('alfakit-workflows::workflows.diagram.rejected'), 'kind' => 'rejected', 'transition' => $name, 'any' => false];
                } elseif ($pending === null && $transition->getRejectedState() !== null) {
                    $edges[] = ['from' => $source, 'to' => $transition->getRejectedState(), 'label' => (string) __('alfakit-workflows::workflows.diagram.rejected'), 'kind' => 'rejected', 'transition' => $name, 'any' => false];
                }
            }
        }

        // One edge per pair and kind: `fromAny()` and pending states can repeat them.
        $unique = [];

        foreach ($edges as $edge) {
            $unique[$edge['from'].'>'.$edge['to'].'>'.$edge['kind'].'>'.$edge['label']] = $edge;
        }

        return array_values($unique);
    }
}
