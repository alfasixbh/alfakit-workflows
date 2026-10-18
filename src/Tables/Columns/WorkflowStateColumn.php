<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitWorkflows\Tables\Columns;

use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\AlfakitWorkflows\Support\Approvals;
use AlfaSix\AlfakitWorkflows\Support\Workflows;
use AlfaSix\AlfakitWorkflows\Workflow;
use BackedEnum;
use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * The record's workflow state as a badge with the state's label, colour and icon.
 * `showPendingApproval()` adds "Awaiting approval" under it.
 *
 * ```php
 * WorkflowStateColumn::make()           // the `state` column
 * WorkflowStateColumn::make('status')   // another state column
 * ```
 */
class WorkflowStateColumn extends TextColumn
{
    protected bool|Closure $showsPendingApproval = false;

    public static function make(string $name = 'state'): static
    {
        return parent::make($name);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('alfakit-workflows::workflows.columns.state'));
        $this->badge();
        $this->formatStateUsing(fn (mixed $state, mixed $record): ?string => ($workflow = $this->workflowFor($record)) === null ? self::scalar($state) : $workflow->getStateLabel(self::scalar($state)));
        $this->color(fn (mixed $state, mixed $record): string => $this->workflowFor($record)?->getState(self::scalar($state))?->getColor() ?? 'gray');
        $this->icon(fn (mixed $state, mixed $record): ?string => $this->workflowFor($record)?->getState(self::scalar($state))?->getIcon());
        $this->description(fn (mixed $record): ?string => $this->evaluate($this->showsPendingApproval) && $record instanceof Model && Workflows::uses($record) && Approvals::pendingFor($record) !== null
            ? (string) __('alfakit-workflows::workflows.columns.awaiting_approval')
            : null);
    }

    /**
     * Show "Awaiting approval" under the badge while an approval is pending (one query per row).
     */
    public function showPendingApproval(bool|Closure $condition = true): static
    {
        $this->showsPendingApproval = $condition;

        return $this;
    }

    protected function workflowFor(mixed $record): ?Workflow
    {
        if ($record instanceof Model && Workflows::uses($record)) {
            return Workflows::for($record);
        }

        $model = $this->getTable()?->getModel();

        return is_string($model) && Workflows::uses($model) ? Workflows::for($model) : null;
    }

    private static function scalar(mixed $state): ?string
    {
        if ($state instanceof BackedEnum) {
            $state = $state->value;
        }

        return is_scalar($state) ? (string) $state : null;
    }
}
