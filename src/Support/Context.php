<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitWorkflows\Support;

use AlfaSix\Alfakit\Panel;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Throwable;

/**
 * The signed-in user, panel and tenant the workflow runs for, and the tenant stored on
 * requests and history so inboxes never show another tenant's work.
 */
final class Context
{
    public static function user(): ?Model
    {
        $user = alfakit()->auth();

        return $user instanceof Model ? $user : null;
    }

    public static function panel(): ?Panel
    {
        return alfakit()->getCurrentPanel() ?? alfakit()->getDefaultPanel();
    }

    /**
     * The current tenant, on tenancy panels.
     */
    public static function tenant(): ?Model
    {
        $panel = self::panel();

        return $panel !== null && $panel->hasTenancy() ? alfakit()->getTenant() : null;
    }

    /**
     * The tenant a record belongs to: the current tenant, else (outside a request, e.g. a
     * queued job) the record's tenant ownership relationship on tenancy panels.
     */
    public static function tenantFor(Model $record): ?Model
    {
        $tenant = self::tenant();

        if ($tenant !== null) {
            return $tenant;
        }

        $panel = self::panel();

        if ($panel === null || ! $panel->hasTenancy()) {
            return null;
        }

        $name = $panel->getTenantOwnershipRelationshipName();

        if (! method_exists($record, $name)) {
            return null;
        }

        try {
            $relation = $record->{$name}();
        } catch (Throwable) {
            return null;
        }

        if (! $relation instanceof BelongsTo) {
            return null;
        }

        $owner = $record->getRelationValue($name);

        return $owner instanceof Model ? $owner : null;
    }

    /**
     * Limit a query on a table with `tenant_type` / `tenant_id` to the current tenant.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function scopeToTenant(Builder $query): Builder
    {
        $panel = self::panel();

        if ($panel === null || ! $panel->hasTenancy()) {
            return $query;
        }

        $tenant = alfakit()->getTenant();

        if ($tenant === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->where($query->qualifyColumn('tenant_type'), $tenant->getMorphClass())
            ->where($query->qualifyColumn('tenant_id'), (string) $tenant->getKey());
    }

    /**
     * Whether a stored tenant (`tenant_type` / `tenant_id`) is the current one.
     */
    public static function isCurrentTenant(?string $type, ?string $id): bool
    {
        $panel = self::panel();

        if ($panel === null || ! $panel->hasTenancy()) {
            return true;
        }

        $tenant = alfakit()->getTenant();

        return $tenant !== null && $type === $tenant->getMorphClass() && $id === (string) $tenant->getKey();
    }

    /**
     * Resolve a stored tenant.
     */
    public static function findTenant(?string $type, ?string $id): ?Model
    {
        if ($type === null || $id === null) {
            return null;
        }

        $class = Relation::getMorphedModel($type) ?? $type;

        if (! class_exists($class) || ! is_a($class, Model::class, true)) {
            return null;
        }

        return $class::query()->find($id);
    }

    /**
     * Run with this panel and tenant as the current ones (the scheduled command), then
     * restore what was there.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public static function runIn(?string $panelId, ?Model $tenant, Closure $callback): mixed
    {
        $previousPanel = alfakit()->getCurrentPanel();
        $previousTenant = alfakit()->getTenant();
        $panels = alfakit()->getPanels();

        if ($panelId !== null && isset($panels[$panelId])) {
            alfakit()->setCurrentPanel($panels[$panelId]);
        }

        alfakit()->setTenant($tenant, isQuiet: true);

        try {
            return $callback();
        } finally {
            alfakit()->setCurrentPanel($previousPanel);
            alfakit()->setTenant($previousTenant, isQuiet: true);
        }
    }
}
