<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitWorkflows;

use AlfaSix\Alfakit\Support\Concerns\EvaluatesClosures;
use AlfaSix\AlfakitWorkflows\Support\Duration;
use Carbon\CarbonInterval;
use Closure;
use DateInterval;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * One level of an approval. Approvers come from `approvers()` (users or a closure),
 * `role()` and/or `permission()`; `anyOf()` (default) needs one of them, `allOf()` every
 * one. Several steps run one after another (multi-level approval).
 *
 * ```php
 * ApprovalStep::make('finance')
 *     ->role('finance')
 *     ->allOf()
 *     ->dueIn('2 days')
 *     ->remindEvery('1 day')
 *     ->escalateAfter('3 days')
 *     ->escalateTo(fn (Invoice $record) => $record->company->cfo)
 *     ->onlyWhen(fn (Invoice $record): bool => $record->total >= 10_000)
 * ```
 */
class ApprovalStep
{
    use EvaluatesClosures;

    public const ANY = 'any';

    public const ALL = 'all';

    protected string|Closure|null $label = null;

    /** @var array<int, mixed> */
    protected array $approvers = [];

    /** @var array<int, string> */
    protected array $roles = [];

    /** @var array<int, string> */
    protected array $permissions = [];

    protected string $mode = self::ANY;

    protected string|DateInterval|Closure|null $dueIn = null;

    protected string|DateInterval|Closure|null $remindEvery = null;

    protected string|DateInterval|Closure|null $escalateAfter = null;

    /** @var array<int, mixed> */
    protected array $escalateTo = [];

    /** @var array<int, string> */
    protected array $escalationRoles = [];

    protected ?Closure $condition = null;

    protected bool|Closure $isApprovalCommentRequired = false;

    protected bool|Closure $isRejectionCommentRequired = true;

    protected bool|Closure $isDelegable = true;

    protected bool|Closure $isBulkApprovable = true;

    final public function __construct(protected string $name) {}

    public static function make(string $name): static
    {
        return new static($name);
    }

    public function getName(): string
    {
        return $this->name;
    }

    protected function getEvaluationIdentifier(): string
    {
        return 'step';
    }

    public function label(string|Closure|null $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getLabel(): string
    {
        $label = $this->evaluate($this->label);

        return is_string($label) && $label !== '' ? $label : Str::headline($this->name);
    }

    // ---------------------------------------------------------------- Approvers

    /**
     * Users, user keys, or `fn (Invoice $record, ?Model $tenant, ?Model $requester): iterable|Model|null`.
     * Call it again to add more.
     *
     * @param  Model|iterable<mixed>|Closure|int|string  $approvers
     */
    public function approvers(Model|iterable|Closure|int|string $approvers): static
    {
        $this->approvers[] = $approvers;

        return $this;
    }

    /**
     * @return array<int, mixed>
     */
    public function getApprovers(): array
    {
        return $this->approvers;
    }

    /**
     * Users with any of these roles (the `role` scope of spatie/laravel-permission when the
     * user model has it, else the user's `hasRole()` method).
     *
     * @param  string|array<int, string>  $roles
     */
    public function role(string|array $roles): static
    {
        $this->roles = array_values(array_unique([...$this->roles, ...(array) $roles]));

        return $this;
    }

    /**
     * @return array<int, string>
     */
    public function getRoles(): array
    {
        return $this->roles;
    }

    /**
     * Users for whom this Gate ability is allowed on the record (checked per candidate,
     * so prefer `role()` or `approvers()` on large user tables).
     *
     * @param  string|array<int, string>  $abilities
     */
    public function permission(string|array $abilities): static
    {
        $this->permissions = array_values(array_unique([...$this->permissions, ...(array) $abilities]));

        return $this;
    }

    /**
     * @return array<int, string>
     */
    public function getPermissions(): array
    {
        return $this->permissions;
    }

    public function hasApproverSource(): bool
    {
        return $this->approvers !== [] || $this->roles !== [] || $this->permissions !== [];
    }

    /**
     * One approval from the approvers is enough (default).
     */
    public function anyOf(): static
    {
        $this->mode = self::ANY;

        return $this;
    }

    /**
     * Every approver must approve.
     */
    public function allOf(): static
    {
        $this->mode = self::ALL;

        return $this;
    }

    public function getMode(): string
    {
        return $this->mode;
    }

    public function requiresAll(): bool
    {
        return $this->mode === self::ALL;
    }

    /**
     * Run the step only when this returns true: `fn (Invoice $record): bool`.
     */
    public function onlyWhen(?Closure $condition): static
    {
        $this->condition = $condition;

        return $this;
    }

    public function appliesTo(Model $record): bool
    {
        return $this->condition === null || (bool) $this->evaluate($this->condition, ['record' => $record], [Model::class => $record, $record::class => $record]);
    }

    // ---------------------------------------------------------------- Timing

    /**
     * When the decision is due ('2 days', '36 hours', an ISO 8601 duration or a DateInterval).
     */
    public function dueIn(string|DateInterval|Closure|null $duration): static
    {
        $this->dueIn = $duration;

        return $this;
    }

    public function getDueIn(): ?CarbonInterval
    {
        return Duration::parse($this->evaluate($this->dueIn));
    }

    /**
     * Remind pending approvers this often (by `alfakit-workflows:process`).
     */
    public function remindEvery(string|DateInterval|Closure|null $duration): static
    {
        $this->remindEvery = $duration;

        return $this;
    }

    public function getRemindEvery(): ?CarbonInterval
    {
        return Duration::parse($this->evaluate($this->remindEvery));
    }

    /**
     * Hand a decision that is still pending this long after it was assigned over to the
     * `escalateTo()` users (by `alfakit-workflows:process`).
     */
    public function escalateAfter(string|DateInterval|Closure|null $duration): static
    {
        $this->escalateAfter = $duration;

        return $this;
    }

    public function getEscalateAfter(): ?CarbonInterval
    {
        return Duration::parse($this->evaluate($this->escalateAfter));
    }

    /**
     * Who escalated decisions go to: users, keys or `fn (Invoice $record, Model $approver): iterable|Model|null`.
     *
     * @param  Model|iterable<mixed>|Closure|int|string  $approvers
     */
    public function escalateTo(Model|iterable|Closure|int|string $approvers): static
    {
        $this->escalateTo[] = $approvers;

        return $this;
    }

    /**
     * @param  string|array<int, string>  $roles
     */
    public function escalateToRole(string|array $roles): static
    {
        $this->escalationRoles = array_values(array_unique([...$this->escalationRoles, ...(array) $roles]));

        return $this;
    }

    /**
     * @return array<int, mixed>
     */
    public function getEscalateTo(): array
    {
        return $this->escalateTo;
    }

    /**
     * @return array<int, string>
     */
    public function getEscalationRoles(): array
    {
        return $this->escalationRoles;
    }

    public function hasEscalationTarget(): bool
    {
        return $this->escalateTo !== [] || $this->escalationRoles !== [];
    }

    // ---------------------------------------------------------------- Decisions

    public function requiresApprovalComment(bool|Closure $condition = true): static
    {
        $this->isApprovalCommentRequired = $condition;

        return $this;
    }

    public function isApprovalCommentRequired(): bool
    {
        return (bool) $this->evaluate($this->isApprovalCommentRequired);
    }

    /**
     * Rejections need a comment (default).
     */
    public function requiresRejectionComment(bool|Closure $condition = true): static
    {
        $this->isRejectionCommentRequired = $condition;

        return $this;
    }

    public function isRejectionCommentRequired(): bool
    {
        return (bool) $this->evaluate($this->isRejectionCommentRequired);
    }

    /**
     * Approvers may hand their decision to someone else (default).
     */
    public function delegable(bool|Closure $condition = true): static
    {
        $this->isDelegable = $condition;

        return $this;
    }

    public function isDelegable(): bool
    {
        return (bool) $this->evaluate($this->isDelegable);
    }

    /**
     * Approvable from the inbox's bulk action (default, unless an approval comment is required).
     */
    public function bulkApprovable(bool|Closure $condition = true): static
    {
        $this->isBulkApprovable = $condition;

        return $this;
    }

    public function isBulkApprovable(): bool
    {
        return (bool) $this->evaluate($this->isBulkApprovable) && ! $this->isApprovalCommentRequired();
    }
}
