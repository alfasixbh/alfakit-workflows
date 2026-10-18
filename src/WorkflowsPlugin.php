<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitWorkflows;

use AlfaSix\Alfakit\Contracts\Plugin;
use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\Support\Concerns\EvaluatesClosures;
use AlfaSix\AlfakitWorkflows\Pages\ApprovalsInbox;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Throwable;

/**
 * State machines and approvals for the models of a panel.
 *
 * ```php
 * $panel->plugin(
 *     WorkflowsPlugin::make()
 *         ->navigationGroup('Work')
 *         ->users(fn (?Model $tenant) => User::query()->where('active', true))
 *         ->mailNotifications(),
 * );
 * ```
 *
 * Every option is optional: without the plugin on a panel, `WorkflowsPlugin::get()`
 * returns a plugin with the defaults (and no inbox page).
 */
class WorkflowsPlugin implements Plugin
{
    use EvaluatesClosures;

    public const ID = 'workflows';

    /** @var class-string<ApprovalsInbox>|null */
    protected ?string $inboxPage = ApprovalsInbox::class;

    protected string|Closure|null $navigationGroup = null;

    protected ?int $navigationSort = null;

    protected string|Closure|null $navigationIcon = 'inbox';

    protected bool|Closure $hasNavigationBadge = true;

    protected ?Closure $usersUsing = null;

    /** @var array<int, string> */
    protected array $userSearchColumns = ['name', 'email'];

    protected bool|Closure $sendsDatabaseNotifications = true;

    protected bool|Closure $sendsMailNotifications = false;

    protected ?Closure $authorizeReassignUsing = null;

    protected ?Closure $recordUrlUsing = null;

    public static function make(): static
    {
        return new static;
    }

    /**
     * The plugin of the current panel, or one with the default options.
     */
    public static function get(): static
    {
        $panel = alfakit()->getCurrentPanel() ?? alfakit()->getDefaultPanel();

        if ($panel !== null && $panel->hasPlugin(self::ID)) {
            $plugin = $panel->getPlugin(self::ID);

            if ($plugin instanceof static) {
                return $plugin;
            }
        }

        return static::make();
    }

    public function getId(): string
    {
        return self::ID;
    }

    public function register(Panel $panel): void
    {
        if ($this->inboxPage !== null) {
            $panel->pages([$this->inboxPage]);
        }
    }

    public function boot(Panel $panel): void {}

    protected function getEvaluationIdentifier(): string
    {
        return 'plugin';
    }

    // ---------------------------------------------------------------- Inbox page

    /**
     * Register the approvals inbox (default), your subclass of it, or nothing (`false`).
     *
     * @param  bool|class-string<ApprovalsInbox>  $page
     */
    public function inbox(bool|string $page = true): static
    {
        if (is_string($page) && ! is_a($page, ApprovalsInbox::class, true)) {
            throw new InvalidArgumentException("The inbox page [{$page}] must extend [".ApprovalsInbox::class.'].');
        }

        $this->inboxPage = $page === true ? ApprovalsInbox::class : ($page === false ? null : $page);

        return $this;
    }

    /**
     * @return class-string<ApprovalsInbox>|null
     */
    public function getInboxPage(): ?string
    {
        return $this->inboxPage;
    }

    public function navigationGroup(string|Closure|null $group): static
    {
        $this->navigationGroup = $group;

        return $this;
    }

    public function getNavigationGroup(): ?string
    {
        $group = $this->evaluate($this->navigationGroup);

        return is_string($group) && $group !== '' ? $group : null;
    }

    public function navigationSort(?int $sort): static
    {
        $this->navigationSort = $sort;

        return $this;
    }

    public function getNavigationSort(): ?int
    {
        return $this->navigationSort;
    }

    public function navigationIcon(string|Closure|null $icon): static
    {
        $this->navigationIcon = $icon;

        return $this;
    }

    public function getNavigationIcon(): ?string
    {
        $icon = $this->evaluate($this->navigationIcon);

        return is_string($icon) && $icon !== '' ? $icon : null;
    }

    /**
     * Show the number of approvals waiting for the user next to the inbox (default).
     */
    public function navigationBadge(bool|Closure $condition = true): static
    {
        $this->hasNavigationBadge = $condition;

        return $this;
    }

    public function hasNavigationBadge(): bool
    {
        return (bool) $this->evaluate($this->hasNavigationBadge);
    }

    // ---------------------------------------------------------------- Users

    /**
     * The users approvers, delegates and escalation targets are chosen from:
     * `fn (?Model $tenant): Builder`. By default the panel's users, limited to the members
     * of the current tenant on tenancy panels.
     */
    public function users(?Closure $callback): static
    {
        $this->usersUsing = $callback;

        return $this;
    }

    /**
     * @return Builder<Model>|null
     */
    public function getUsersQuery(?Model $tenant): ?Builder
    {
        if ($this->usersUsing === null) {
            return null;
        }

        $query = $this->evaluate($this->usersUsing, ['tenant' => $tenant], $tenant === null ? [] : [Model::class => $tenant, $tenant::class => $tenant]);

        return $query instanceof Builder ? $query : null;
    }

    /**
     * The columns the delegate search looks in (`name` and `email` by default).
     *
     * @param  array<int, string>  $columns
     */
    public function userSearchColumns(array $columns): static
    {
        $this->userSearchColumns = array_values($columns);

        return $this;
    }

    /**
     * @return array<int, string>
     */
    public function getUserSearchColumns(): array
    {
        return $this->userSearchColumns;
    }

    // ---------------------------------------------------------------- Notifications

    /**
     * Notify approvers and requesters in the panel's bell (default).
     */
    public function databaseNotifications(bool|Closure $condition = true): static
    {
        $this->sendsDatabaseNotifications = $condition;

        return $this;
    }

    public function sendsDatabaseNotifications(): bool
    {
        return (bool) $this->evaluate($this->sendsDatabaseNotifications);
    }

    /**
     * Also email them (Laravel's mail channel; off by default).
     */
    public function mailNotifications(bool|Closure $condition = true): static
    {
        $this->sendsMailNotifications = $condition;

        return $this;
    }

    public function sendsMailNotifications(): bool
    {
        return (bool) $this->evaluate($this->sendsMailNotifications);
    }

    /**
     * The link in notifications: `fn (Model $record): ?string`. Defaults to the view (or
     * edit) page of the record's resource in the panel.
     */
    public function recordUrlUsing(?Closure $callback): static
    {
        $this->recordUrlUsing = $callback;

        return $this;
    }

    public function getRecordUrl(Model $record): ?string
    {
        if ($this->recordUrlUsing !== null) {
            $url = $this->evaluate($this->recordUrlUsing, ['record' => $record], [Model::class => $record, $record::class => $record]);

            return is_string($url) && $url !== '' ? $url : null;
        }

        $panel = alfakit()->getCurrentPanel() ?? alfakit()->getDefaultPanel();
        $resource = $panel?->getModelResource($record);

        if ($resource === null) {
            return null;
        }

        foreach (['view', 'edit'] as $page) {
            if ($resource::hasPage($page)) {
                try {
                    return $resource::getUrl($page, ['record' => $record], panel: $panel);
                } catch (Throwable) {
                    return null;
                }
            }
        }

        return null;
    }

    // ---------------------------------------------------------------- Reassigning

    /**
     * Who may reassign other people's pending approvals and withdraw requests:
     * `fn (Model $record, Model $user): bool`. By default a `reassignApprovals` policy
     * method on the record; nobody when there is none.
     */
    public function authorizeReassignUsing(?Closure $callback): static
    {
        $this->authorizeReassignUsing = $callback;

        return $this;
    }

    public function canReassign(Model $record, ?Model $user): bool
    {
        if ($user === null) {
            return false;
        }

        if ($this->authorizeReassignUsing !== null) {
            return (bool) $this->evaluate($this->authorizeReassignUsing, ['record' => $record, 'user' => $user], [Model::class => $record, $record::class => $record]);
        }

        $policy = Gate::getPolicyFor($record);

        if ($policy === null || ! method_exists($policy, 'reassignApprovals')) {
            return false;
        }

        return Gate::forUser($user)->check('reassignApprovals', $record);
    }
}
