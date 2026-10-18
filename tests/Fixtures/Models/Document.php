<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitWorkflows\Tests\Fixtures\Models;

use AlfaSix\AlfakitWorkflows\Concerns\HasWorkflow;
use AlfaSix\AlfakitWorkflows\Support\Workflows;
use AlfaSix\AlfakitWorkflows\Transition;
use AlfaSix\AlfakitWorkflows\Workflow;
use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * A model whose workflow each test defines (`Document::define(fn () => Workflow::make(...))`).
 */
class Document extends Model
{
    use HasWorkflow;

    protected $table = 'wf_documents';

    protected $guarded = [];

    public static ?Closure $definition = null;

    public static function define(?Closure $definition): void
    {
        static::$definition = $definition;
        Workflows::flush();
    }

    public static function workflow(): Workflow
    {
        if (static::$definition !== null) {
            return (static::$definition)();
        }

        return Workflow::make('document')
            ->stateColumn('status')
            ->states(['draft', 'published'])
            ->transitions([Transition::make('publish')->from('draft')->to('published')])
            ->initial('draft');
    }
}
