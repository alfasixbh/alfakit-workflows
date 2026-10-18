<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitWorkflows\Support;

use AlfaSix\Alfakit\Contracts\HasName;
use AlfaSix\Alfakit\Contracts\HasTenants;
use AlfaSix\Alfakit\Support\Html;
use AlfaSix\AlfakitWorkflows\WorkflowsPlugin;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Approver resolution (users, keys, closures, roles, permissions), the candidate users of
 * a tenant, and display names.
 */
final class Users
{
    /** Tenant relationships tried, in order, for the members of a tenant. */
    public const MEMBER_RELATIONSHIPS = ['members', 'users'];

    /**
     * The users approvals can go to: the plugin's `users()`, else the panel's users,
     * limited to the tenant's members.
     *
     * @return Builder<Model>|null
     */
    public static function candidates(?Model $tenant): ?Builder
    {
        $query = WorkflowsPlugin::get()->getUsersQuery($tenant);

        if ($query !== null) {
            return $query;
        }

        $model = self::userModel();

        if ($model === null) {
            return null;
        }

        /** @var Builder<Model> $query */
        $query = $model::query();

        if ($tenant === null) {
            return $query;
        }

        foreach (self::MEMBER_RELATIONSHIPS as $relationship) {
            if (! method_exists($tenant, $relationship)) {
                continue;
            }

            $relation = $tenant->{$relationship}();
            $instance = $query->getModel();

            if ($relation instanceof Relation && $relation->getRelated()->getTable() === $instance->getTable()) {
                return $query->whereIn(
                    $instance->getQualifiedKeyName(),
                    $relation->getQuery()->toBase()->select($relation->getRelated()->getQualifiedKeyName()),
                );
            }
        }

        return $query;
    }

    /**
     * Resolve approver sources (users, collections, keys, closures) plus roles and
     * permissions into distinct users who can access the tenant.
     *
     * @param  array<int, mixed>  $sources
     * @param  array<int, string>  $roles
     * @param  array<int, string>  $permissions
     * @param  array<string, mixed>  $injections
     * @return Collection<int, Model>
     */
    public static function resolve(array $sources, array $roles, array $permissions, Model $record, ?Model $tenant, array $injections, ?Closure $evaluate = null): Collection
    {
        $users = collect();

        foreach ($sources as $source) {
            if ($source instanceof Closure) {
                $source = $evaluate === null ? $source() : $evaluate($source, $injections);
            }

            foreach (self::flatten($source) as $item) {
                $user = $item instanceof Model ? $item : self::findCandidate($item, $tenant);

                if ($user !== null) {
                    $users->push($user);
                }
            }
        }

        if ($roles !== []) {
            $users = $users->merge(self::withRoles($roles, $tenant));
        }

        if ($permissions !== []) {
            $candidates = self::candidates($tenant);

            foreach ($candidates?->get() ?? [] as $candidate) {
                foreach ($permissions as $permission) {
                    if (Gate::forUser($candidate)->check($permission, $record)) {
                        $users->push($candidate);

                        break;
                    }
                }
            }
        }

        return self::unique($users)->filter(static fn (Model $user): bool => self::canAccessTenant($user, $tenant))->values();
    }

    /**
     * @param  array<int, string>  $roles
     * @return Collection<int, Model>
     */
    public static function withRoles(array $roles, ?Model $tenant): Collection
    {
        $query = self::candidates($tenant);

        if ($query === null) {
            return collect();
        }

        // spatie/laravel-permission's `role` scope.
        if (method_exists($query->getModel(), 'scopeRole')) {
            return $query->scopes(['role' => [$roles]])->get()->toBase();
        }

        return $query->get()
            ->filter(static function (Model $user) use ($roles): bool {
                if (! method_exists($user, 'hasRole')) {
                    return false;
                }

                foreach ($roles as $role) {
                    if ($user->hasRole($role)) {
                        return true;
                    }
                }

                return false;
            })
            ->values()
            ->toBase();
    }

    public static function findCandidate(mixed $key, ?Model $tenant): ?Model
    {
        if (! is_scalar($key) || (string) $key === '') {
            return null;
        }

        return self::candidates($tenant)?->whereKey($key)->first();
    }

    /**
     * A stored user (`approver_type` / `approver_id`), whether or not they are still a candidate.
     */
    public static function find(?string $type, ?string $id): ?Model
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
     * Delegate search results, `key => name`.
     *
     * @param  array<int, string>  $exclude  Keys left out (the user, the current approvers)
     * @return array<string, string>
     */
    public static function search(string $search, ?Model $tenant, array $exclude = [], int $limit = 20): array
    {
        $query = self::candidates($tenant);

        if ($query === null) {
            return [];
        }

        $search = trim($search);

        if ($search !== '') {
            $columns = WorkflowsPlugin::get()->getUserSearchColumns();
            $like = '%'.mb_strtolower($search).'%';
            $table = $query->getModel()->getTable();
            $existing = \Illuminate\Support\Facades\Schema::connection($query->getModel()->getConnectionName())->getColumnListing($table);
            $columns = array_values(array_intersect($columns, $existing));

            $query->where(static function (Builder $query) use ($columns, $like): void {
                foreach ($columns as $column) {
                    $query->orWhereRaw('LOWER('.$query->getQuery()->getGrammar()->wrap($query->qualifyColumn($column)).') LIKE ?', [$like]);
                }
            });
        }

        if ($exclude !== []) {
            $query->whereNotIn($query->getModel()->getQualifiedKeyName(), $exclude);
        }

        $results = [];

        foreach ($query->limit($limit * 3)->get() as $user) {
            if (! self::canAccessTenant($user, $tenant)) {
                continue;
            }

            $results[(string) $user->getKey()] = self::name($user);

            if (count($results) >= $limit) {
                break;
            }
        }

        return $results;
    }

    public static function canAccessTenant(Model $user, ?Model $tenant): bool
    {
        return $tenant === null || ! $user instanceof HasTenants || $user->canAccessTenant($tenant);
    }

    public static function name(?Model $user): string
    {
        if ($user === null) {
            return (string) __('alfakit-workflows::workflows.system');
        }

        $panel = alfakit()->getCurrentPanel() ?? alfakit()->getDefaultPanel();

        if ($panel !== null && $user instanceof Authenticatable) {
            return $panel->getUserName($user);
        }

        if ($user instanceof HasName) {
            return Html::toText($user->getAlfakitName());
        }

        $name = $user->getAttribute('name') ?? $user->getAttribute('email') ?? $user->getKey();

        return is_scalar($name) ? (string) $name : '';
    }

    public static function same(?Model $a, ?Model $b): bool
    {
        return $a !== null && $b !== null && $a->getMorphClass() === $b->getMorphClass() && (string) $a->getKey() === (string) $b->getKey();
    }

    /**
     * @return class-string<Model>|null
     */
    public static function userModel(): ?string
    {
        $panel = alfakit()->getCurrentPanel() ?? alfakit()->getDefaultPanel();
        $guard = $panel?->getAuthGuard() ?? config('auth.defaults.guard');
        $provider = config("auth.guards.{$guard}.provider");
        $model = is_string($provider) ? config("auth.providers.{$provider}.model") : null;

        return is_string($model) && is_a($model, Model::class, true) ? $model : null;
    }

    /**
     * @return array<int, mixed>
     */
    private static function flatten(mixed $source): array
    {
        if ($source === null || $source === false) {
            return [];
        }

        if ($source instanceof Model || is_scalar($source)) {
            return [$source];
        }

        if (is_iterable($source)) {
            $items = [];

            foreach ($source as $item) {
                array_push($items, ...self::flatten($item));
            }

            return $items;
        }

        return [];
    }

    /**
     * @param  Collection<int, Model>  $users
     * @return Collection<int, Model>
     */
    private static function unique(Collection $users): Collection
    {
        return $users->unique(static fn (Model $user): string => $user->getMorphClass().'#'.$user->getKey())->values();
    }
}
