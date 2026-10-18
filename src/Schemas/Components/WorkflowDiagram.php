<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitWorkflows\Schemas\Components;

use AlfaSix\Alfakit\Schemas\Components\Html;
use AlfaSix\AlfakitWorkflows\Models\WorkflowLog;
use AlfaSix\AlfakitWorkflows\Support\DiagramRenderer;
use AlfaSix\AlfakitWorkflows\Support\Workflows;
use AlfaSix\AlfakitWorkflows\Workflow;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * A read-only diagram of the workflow, drawn as SVG on the server (no diagram library):
 * the record's current state is highlighted and the transitions it went through are
 * drawn solid. Use it in an infolist, a form, a page or an action modal:
 *
 * ```php
 * WorkflowDiagram::make()                        // the schema record's workflow
 * WorkflowDiagram::make()->workflow(Invoice::class) // a model's workflow, no record
 * ```
 */
class WorkflowDiagram extends Html
{
    /** Rendered by the renderers' `Html`: no frontend code of its own. */
    protected ?string $type = 'Html';

    /** @var class-string<Model>|Workflow|Closure|null */
    protected string|Workflow|Closure|null $workflow = null;

    protected Model|Closure|null $subject = null;

    protected bool|Closure $showsHistory = true;

    public function __construct()
    {
        parent::__construct(fn (): HtmlString => new HtmlString($this->renderDiagram()));
    }

    public static function make(mixed ...$arguments): static
    {
        return parent::make(...$arguments);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->key('workflow-diagram');
    }

    /**
     * The workflow to draw: a model class using `HasWorkflow`, or a `Workflow`.
     *
     * @param  class-string<Model>|Workflow|Closure|null  $workflow
     */
    public function workflow(string|Workflow|Closure|null $workflow): static
    {
        $this->workflow = $workflow;

        return $this;
    }

    /**
     * Draw the workflow of another record than the schema's.
     */
    public function subject(Model|Closure|null $record): static
    {
        $this->subject = $record;

        return $this;
    }

    /**
     * Draw the transitions the record took solid (default).
     */
    public function showHistory(bool|Closure $condition = true): static
    {
        $this->showsHistory = $condition;

        return $this;
    }

    public function getSubject(): ?Model
    {
        $record = $this->evaluate($this->subject);

        if ($record instanceof Model) {
            return $record;
        }

        try {
            $record = $this->getRecord();
        } catch (Throwable) {
            return null;
        }

        return $record instanceof Model && Workflows::uses($record) ? $record : null;
    }

    public function getWorkflowDefinition(): ?Workflow
    {
        $workflow = $this->evaluate($this->workflow);

        if ($workflow instanceof Workflow) {
            return $workflow->validate();
        }

        if (is_string($workflow) && Workflows::uses($workflow)) {
            return Workflows::for($workflow);
        }

        $record = $this->getSubject();

        return $record === null ? null : Workflows::for($record);
    }

    public function isHidden(): bool
    {
        return parent::isHidden() || $this->getWorkflowDefinition() === null;
    }

    protected function renderDiagram(): string
    {
        $workflow = $this->getWorkflowDefinition();

        if ($workflow === null) {
            return '';
        }

        $record = $this->getSubject();
        $current = $record === null ? null : $workflow->getStateOf($record);
        $taken = [];

        if ($record !== null && $record->exists && $this->evaluate($this->showsHistory)) {
            $taken = WorkflowLog::query()
                ->forSubject($record)
                ->whereIn('event', [WorkflowLog::TRANSITIONED, WorkflowLog::REQUESTED, WorkflowLog::REJECTED, WorkflowLog::CANCELLED])
                ->whereNotNull('from_state')
                ->whereNotNull('to_state')
                ->get(['from_state', 'to_state'])
                ->map(static fn (WorkflowLog $log): array => [(string) $log->from_state, (string) $log->to_state])
                ->all();
        }

        return DiagramRenderer::render($workflow, $current, $taken);
    }
}
