<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitWorkflows\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One approver's decision on one step of an approval request. Delegating or escalating
 * closes it (`delegated` / `escalated`) and opens a new one that `replaces` it.
 *
 * @property int $id
 * @property int $request_id
 * @property int $step
 * @property string $step_name
 * @property string $approver_type
 * @property string $approver_id
 * @property string $status
 * @property int|null $replaces_id
 * @property string|null $comment
 * @property \Illuminate\Support\Carbon|null $due_at
 * @property \Illuminate\Support\Carbon|null $last_reminded_at
 * @property int $reminders_sent
 * @property \Illuminate\Support\Carbon|null $escalated_at
 * @property \Illuminate\Support\Carbon|null $decided_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read ApprovalRequest|null $request
 * @property-read Model|null $approver
 * @property-read Approval|null $replaces
 */
class Approval extends Model
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const DELEGATED = 'delegated';

    public const ESCALATED = 'escalated';

    /** Closed without a decision: the step or request finished first. */
    public const SKIPPED = 'skipped';

    protected $table = 'alfakit_workflow_approvals';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'step' => 'integer',
            'reminders_sent' => 'integer',
            'due_at' => 'datetime',
            'last_reminded_at' => 'datetime',
            'escalated_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ApprovalRequest, $this>
     */
    public function request(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class, 'request_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function approver(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<Approval, $this>
     */
    public function replaces(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaces_id');
    }

    /**
     * @param  Builder<Approval>  $query
     * @return Builder<Approval>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('status'), self::PENDING);
    }

    /**
     * @param  Builder<Approval>  $query
     * @return Builder<Approval>
     */
    public function scopeAssignedTo(Builder $query, Model $user): Builder
    {
        return $query
            ->where($this->qualifyColumn('approver_type'), $user->getMorphClass())
            ->where($this->qualifyColumn('approver_id'), (string) $user->getKey());
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    public function isAssignedTo(?Model $user): bool
    {
        return $user !== null
            && $this->approver_type === $user->getMorphClass()
            && $this->approver_id === (string) $user->getKey();
    }

    public function isOverdue(): bool
    {
        return $this->isPending() && $this->due_at !== null && $this->due_at->isPast();
    }
}
