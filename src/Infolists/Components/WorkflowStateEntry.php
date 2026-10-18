<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitWorkflows\Infolists\Components;

use AlfaSix\Alfakit\Infolists\Components\TextEntry;
use AlfaSix\AlfakitWorkflows\Support\Approvals;
use AlfaSix\AlfakitWorkflows\Support\Workflows;
use AlfaSix\AlfakitWorkflows\Workflow;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;

/**
 * The record's workflow state as a badge (label, colour, icon), with "Awaiting approval"
 * under it while an approval is pending.
 *
 * ```php
 * WorkflowStateEntry::make()          // the `state` attribute
 * ```
 */
class WorkflowStateEntry extends TextEntry
{
    public static function make(mixed ...$arguments): static
    {
        return parent::make(...($arguments === [] ? ['state'] : $arguments));
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('alfakit-workflows::workflows.columns.state'));
        $this->badge();
        $this->formatStateUsing(fn (mixed $state): ?string => $this->workflow()?->getStateLabel(self::scalar($state)) ?? self::scalar($state));
        $this->color(fn (mixed $state): string => $this->workflow()?->getState(self::scalar($state))?->getColor() ?? 'gray');
        $this->icon(fn (mixed $state): ?string => $this->workflow()?->getState(self::scalar($state))?->getIcon());
        $this->helperText(function (): ?string {
            $record = $this->getRecord();

            return $record instanceof Model && $record->exists && Workflows::uses($record) && Approvals::pendingFor($record) !== null
                ? (string) __('alfakit-workflows::workflows.columns.awaiting_approval')
                : null;
        });
    }

    protected function workflow(): ?Workflow
    {
        $record = $this->getRecord();

        if ($record instanceof Model && Workflows::uses($record)) {
            return Workflows::for($record);
        }

        $model = $this->getModel();

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
