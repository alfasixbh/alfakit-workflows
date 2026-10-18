<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitWorkflows\Infolists\Components;

use AlfaSix\Alfakit\Infolists\Components\TextEntry;
use AlfaSix\Alfakit\Schemas\Components\Callout;
use AlfaSix\Alfakit\Schemas\Components\Component;
use AlfaSix\Alfakit\Schemas\Components\Flex;
use AlfaSix\Alfakit\Schemas\Components\Group;
use AlfaSix\Alfakit\Schemas\Components\Icon;
use AlfaSix\Alfakit\Schemas\Components\Text;
use AlfaSix\Alfakit\Support\Authorization;
use AlfaSix\AlfakitWorkflows\Models\Approval;
use AlfaSix\AlfakitWorkflows\Models\ApprovalRequest;
use AlfaSix\AlfakitWorkflows\Models\WorkflowLog;
use AlfaSix\AlfakitWorkflows\Support\Approvals;
use AlfaSix\AlfakitWorkflows\Support\Users;
use AlfaSix\AlfakitWorkflows\Support\Workflows;
use AlfaSix\AlfakitWorkflows\Workflow;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * The record's workflow history: who moved it, when, from which state to which, their
 * comments, every approval decision, delegation and escalation, and (while approval is
 * pending) who it is waiting for and by when. Built from AlfaKit components, so it
 * renders in every renderer.
 *
 * ```php
 * Section::make('History')->schema([WorkflowTimeline::make()->limit(25)])
 * ```
 */
class WorkflowTimeline extends Group
{
    /** Rendered by the renderers' `Group`: no frontend code of its own. */
    protected ?string $type = 'Group';

    protected int|Closure|null $limit = 50;

    protected bool|Closure $isNewestFirst = true;

    protected bool|Closure $showsPending = true;

    protected Model|Closure|null $subject = null;

    /** @var array<Component>|null */
    protected ?array $cachedComponents = null;

    final public function __construct(protected string $name = 'workflow-timeline')
    {
        parent::__construct();
    }

    public static function make(mixed ...$arguments): static
    {
        return parent::make(...$arguments);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->key($this->name);
        $this->columns(1);
        $this->columnSpanFull();
        $this->extraAttributes(['data-workflow-timeline' => true, 'role' => 'list', 'aria-label' => __('alfakit-workflows::workflows.timeline.label')]);
    }

    /**
     * Show the history of another record than the schema's.
     */
    public function subject(Model|Closure|null $record): static
    {
        $this->subject = $record;

        return $this;
    }

    public function getSubject(): ?Model
    {
        $record = $this->evaluate($this->subject) ?? $this->getRecord();

        return $record instanceof Model && $record->exists && Workflows::uses($record) ? $record : null;
    }

    /**
     * How many entries (newest first by default); null for all.
     */
    public function limit(int|Closure|null $limit): static
    {
        $this->limit = $limit;

        return $this;
    }

    public function oldestFirst(bool|Closure $condition = true): static
    {
        $this->isNewestFirst = $condition instanceof Closure ? fn (): bool => ! $this->evaluate($condition) : ! $condition;

        return $this;
    }

    /**
     * Show who a pending approval is waiting for (default).
     */
    public function showPendingApprovals(bool|Closure $condition = true): static
    {
        $this->showsPending = $condition;

        return $this;
    }

    public function isHidden(): bool
    {
        if (parent::isHidden()) {
            return true;
        }

        $record = $this->getSubject();

        return $record === null || ! Authorization::allows('view', $record);
    }

    /**
     * @return array<Component>
     */
    public function getChildComponents(): array
    {
        return $this->cachedComponents ??= $this->buildComponents();
    }

    /**
     * @return Collection<int, WorkflowLog>
     */
    public function getEntries(Model $record): Collection
    {
        $newestFirst = (bool) $this->evaluate($this->isNewestFirst);
        $query = WorkflowLog::query()
            ->forSubject($record)
            ->with('causer')
            ->orderBy('created_at', $newestFirst ? 'desc' : 'asc')
            ->orderBy('id', $newestFirst ? 'desc' : 'asc');
        $limit = $this->evaluate($this->limit);

        if (is_int($limit)) {
            $query->limit(max(1, $limit));
        }

        return $query->get()->toBase();
    }

    /**
     * @return array<Component>
     */
    protected function buildComponents(): array
    {
        $record = $this->getSubject();

        if ($record === null) {
            return [];
        }

        $workflow = Workflows::for($record);
        $components = [];

        if ($this->evaluate($this->showsPending) && ($request = Approvals::pendingFor($record)) !== null) {
            $components[] = $this->makePendingCallout($workflow, $request);
        }

        $entries = $this->getEntries($record);

        if ($entries->isEmpty()) {
            $components[] = Text::make(__('alfakit-workflows::workflows.timeline.empty'))->color('gray')->key($this->name.'-empty');
        }

        foreach ($entries as $entry) {
            $components[] = $this->makeEntry($workflow, $entry);
        }

        return $components;
    }

    protected function makePendingCallout(Workflow $workflow, ApprovalRequest $request): Component
    {
        $transition = $workflow->getTransition($request->transition);
        $step = $transition?->getApprovalStep($request->current_step);
        $approvals = $request->approvals()->with('approver')->where('step', $request->current_step)->orderBy('id')->get();
        $key = $this->name.'-pending';
        $rows = [];

        foreach ($approvals as $approval) {
            if (! in_array($approval->status, [Approval::PENDING, Approval::APPROVED], true)) {
                continue;
            }

            // Side by side from `sm`; stacked on phones, where name, badge and due date don't fit one row.
            $rows[] = Flex::make(array_values(array_filter([
                Icon::make($approval->status === Approval::APPROVED ? 'circle-check' : 'hourglass')
                    ->color($approval->status === Approval::APPROVED ? 'success' : 'warning')
                    ->size('sm')
                    ->tooltip(__('alfakit-workflows::workflows.statuses.'.$approval->status))
                    ->grow(false),
                Text::make(Users::name($approval->approver))->weight('medium')->grow(false),
                Text::make(__('alfakit-workflows::workflows.statuses.'.$approval->status))
                    ->badge()
                    ->color($approval->status === Approval::APPROVED ? 'success' : ($approval->isOverdue() ? 'danger' : 'warning'))
                    ->grow(false),
                $approval->due_at === null || $approval->status !== Approval::PENDING ? null : TextEntry::make("{$key}-{$approval->getKey()}-due")
                    ->hiddenLabel()
                    ->state($approval->due_at)
                    ->since()
                    ->dateTimeTooltip()
                    ->prefix(__('alfakit-workflows::workflows.timeline.due').' ')
                    ->color($approval->isOverdue() ? 'danger' : 'gray')
                    ->size('sm')
                    ->grow(false),
            ])))->from('sm')->verticallyAlignCenter()->key("{$key}-{$approval->getKey()}");
        }

        $mode = $step?->requiresAll() ? 'all' : 'any';

        return Callout::make(__('alfakit-workflows::workflows.timeline.pending', [
            'transition' => (string) $transition?->getLabel(),
            'step' => (string) $step?->getLabel(),
        ]))
            ->description(__('alfakit-workflows::workflows.timeline.pending_'.$mode, [
                'step' => (string) ($request->current_step + 1),
                'steps' => (string) count($transition?->getApprovalSteps() ?? []),
            ]))
            ->icon('hourglass')
            ->warning()
            ->footer($rows === [] ? null : Group::make($rows)->dense()->key("{$key}-approvers"))
            ->key($key);
    }

    protected function makeEntry(Workflow $workflow, WorkflowLog $entry): Component
    {
        $key = $this->name.'-'.$entry->getKey();
        $properties = $entry->properties ?? [];
        $name = Users::name($entry->causer);
        $transition = $entry->transition === null ? null : ($workflow->getTransition($entry->transition)?->getLabel() ?? $entry->transition);
        $event = in_array($entry->event, [
            WorkflowLog::CREATED, WorkflowLog::TRANSITIONED, WorkflowLog::REQUESTED, WorkflowLog::APPROVED, WorkflowLog::REJECTED,
            WorkflowLog::DELEGATED, WorkflowLog::REASSIGNED, WorkflowLog::ESCALATED, WorkflowLog::CANCELLED,
        ], true) ? $entry->event : WorkflowLog::TRANSITIONED;
        $summaryKey = $event === WorkflowLog::TRANSITIONED && ($properties['approved'] ?? false) ? 'transitioned_approved' : $event;

        $summary = (string) __('alfakit-workflows::workflows.timeline.events.'.$summaryKey, [
            'name' => $name,
            'transition' => (string) $transition,
            'step' => is_string($properties['step_label'] ?? null) ? $properties['step_label'] : '',
            'from' => is_string($properties['from'] ?? null) ? $properties['from'] : '',
            'to' => is_string($properties['to'] ?? null) ? $properties['to'] : '',
            'state' => (string) $workflow->getStateLabel($entry->to_state),
        ]);

        [$icon, $color] = match ($event) {
            WorkflowLog::CREATED => ['sparkles', 'gray'],
            WorkflowLog::REQUESTED => ['send', 'info'],
            WorkflowLog::APPROVED => ['circle-check', 'success'],
            WorkflowLog::REJECTED => ['circle-x', 'danger'],
            WorkflowLog::DELEGATED, WorkflowLog::REASSIGNED => ['forward', 'gray'],
            WorkflowLog::ESCALATED => ['siren', 'warning'],
            WorkflowLog::CANCELLED => ['undo-2', 'gray'],
            default => ['arrow-right-left', 'primary'],
        };

        $details = [
            Flex::make([
                Text::make($summary)->weight('semibold')->key("{$key}-summary")->grow(false),
                TextEntry::make("{$key}-time")
                    ->hiddenLabel()
                    ->state($entry->created_at)
                    ->since()
                    ->dateTimeTooltip()
                    ->color('gray')
                    ->size('sm')
                    ->grow(false),
                // Summary and time side by side from `sm`; stacked on phones, where long summaries don't fit.
            ])->from('sm')->verticallyAlignCenter()->key("{$key}-header"),
        ];

        if ($entry->from_state !== null && $entry->to_state !== null && $entry->event !== WorkflowLog::CREATED) {
            $details[] = Flex::make([
                $this->stateBadge($workflow, $entry->from_state, "{$key}-from"),
                Icon::make('arrow-right')->size('sm')->color('gray')->extraAttributes(['class' => 'rtl:rotate-180', 'aria-hidden' => 'true'])->grow(false),
                $this->stateBadge($workflow, $entry->to_state, "{$key}-to"),
            ])->from('sm')->verticallyAlignCenter()->key("{$key}-states");
        } elseif ($entry->event === WorkflowLog::CREATED && $entry->to_state !== null) {
            $details[] = Flex::make([$this->stateBadge($workflow, $entry->to_state, "{$key}-to")])->key("{$key}-states");
        }

        if ($entry->comment !== null) {
            $details[] = Text::make('“'.$entry->comment.'”')->color('gray')->key("{$key}-comment");
        }

        return Flex::make([
            Icon::make($icon)->color($color)->tooltip(__('alfakit-workflows::workflows.timeline.kinds.'.$event))->grow(false),
            Group::make($details)->dense()->key("{$key}-content"),
        ])
            ->verticallyAlignStart()
            ->key($key)
            ->extraAttributes(['role' => 'listitem', 'data-workflow-event' => $entry->event]);
    }

    protected function stateBadge(Workflow $workflow, string $state, string $key): Text
    {
        $definition = $workflow->getState($state);

        return Text::make((string) $workflow->getStateLabel($state))
            ->badge()
            ->color($definition?->getColor() ?? 'gray')
            ->icon($definition?->getIcon())
            ->key($key)
            ->grow(false);
    }
}
