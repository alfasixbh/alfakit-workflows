<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitWorkflows\Tables\Filters;

use AlfaSix\Alfakit\Tables\Filters\SelectFilter;
use AlfaSix\AlfakitWorkflows\Support\Workflows;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Filter by workflow state (several at once), with the workflow's state labels.
 *
 * ```php
 * WorkflowStateFilter::make()                    // the `state` column of the table's model
 * WorkflowStateFilter::make('status')->workflow(Invoice::class)
 * ```
 */
class WorkflowStateFilter extends SelectFilter
{
    /** @var class-string<Model>|null */
    protected ?string $workflowModel = null;

    public static function make(string $name = 'state'): static
    {
        return parent::make($name);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('alfakit-workflows::workflows.columns.state'));
        $this->multiple();
        $this->options(function (): array {
            $model = $this->workflowModel ?? $this->getTable()?->getModel();

            return is_string($model) && Workflows::uses($model) ? Workflows::for($model)->getStateOptions() : [];
        });
    }

    /**
     * The model whose workflow gives the states (default: the table's model).
     *
     * @param  class-string<Model>  $model
     */
    public function workflow(string $model): static
    {
        if (! Workflows::uses($model)) {
            throw new InvalidArgumentException("The model [{$model}] does not use the HasWorkflow trait.");
        }

        $this->workflowModel = $model;

        return $this;
    }
}
