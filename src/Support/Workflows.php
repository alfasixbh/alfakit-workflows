<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitWorkflows\Support;

use AlfaSix\AlfakitWorkflows\Concerns\HasWorkflow;
use AlfaSix\AlfakitWorkflows\Workflow;
use Closure;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * The validated workflow of each model class, built once per application instance (the
 * container singleton is rebuilt for every Octane request).
 */
final class Workflows
{
    /** @var array<class-string, Workflow> */
    private array $workflows = [];

    private int $applying = 0;

    public static function instance(): self
    {
        if (! app()->bound(self::class)) {
            app()->instance(self::class, new self);
        }

        return app(self::class);
    }

    /**
     * @param  Model|class-string<Model>  $model
     */
    public static function for(Model|string $model): Workflow
    {
        $class = is_string($model) ? $model : $model::class;

        if (! self::uses($class)) {
            throw new InvalidArgumentException("The model [{$class}] does not use the [".HasWorkflow::class.'] trait.');
        }

        $instance = self::instance();

        if (! isset($instance->workflows[$class])) {
            $workflow = call_user_func([$class, 'workflow']);

            if (! $workflow instanceof Workflow) {
                throw new InvalidArgumentException("[{$class}::workflow()] must return a ".Workflow::class.'.');
            }

            $instance->workflows[$class] = $workflow->validate();
        }

        return $instance->workflows[$class];
    }

    /**
     * @param  Model|class-string|null  $model
     */
    public static function uses(Model|string|null $model): bool
    {
        if ($model === null || (is_string($model) && ! class_exists($model))) {
            return false;
        }

        return in_array(HasWorkflow::class, class_uses_recursive($model), true);
    }

    /**
     * Forget the cached definitions (tests that redefine a workflow).
     */
    public static function flush(): void
    {
        self::instance()->workflows = [];
    }

    /**
     * Run a callback that may save a new state (the trait refuses state changes otherwise).
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public static function applying(Closure $callback): mixed
    {
        $instance = self::instance();
        $instance->applying++;

        try {
            return $callback();
        } finally {
            $instance->applying--;
        }
    }

    public static function isApplying(): bool
    {
        return self::instance()->applying > 0;
    }
}
