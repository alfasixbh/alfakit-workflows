<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitWorkflows\Models;

use AlfaSix\AlfakitWorkflows\ApprovalStep;
use AlfaSix\AlfakitWorkflows\Concerns\HasWorkflow;
use AlfaSix\AlfakitWorkflows\Support\Workflows;
use AlfaSix\AlfakitWorkflows\Transition;
use AlfaSix\AlfakitWorkflows\Workflow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A transition waiting for its approval steps. `current_step` is the index of the step
 * whose approvals are open.
 *
 * @property int $id
 * @property string $workflow
 * @property string $transition
 * @property string $subject_type
 * @property string $subject_id
 * @property string|null $tenant_type
 * @property string|null $tenant_id
 * @property string|null $panel
 * @property string|null $requester_type
 * @property string|null $requester_id
 * @property string $status
 * @property int $current_step
 * @property string|null $from_state
 * @property array<string, mixed>|null $data
 * @property string|null $comment
 * @property \Illuminate\Support\Carbon|null $completed_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Model|null $subject
 * @property-read Model|null $requester
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Approval> $approvals
 */
class ApprovalRequest extends Model
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const CANCELLED = 'cancelled';

    protected $table = 'alfakit_workflow_approval_requests';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'current_step' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function requester(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return HasMany<Approval, $this>
     */
    public function approvals(): HasMany
    {
        return $this->hasMany(Approval::class, 'request_id');
    }

    /**
     * @param  Builder<ApprovalRequest>  $query
     * @return Builder<ApprovalRequest>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('status'), self::PENDING);
    }

    /**
     * @param  Builder<ApprovalRequest>  $query
     * @return Builder<ApprovalRequest>
     */
    public function scopeForSubject(Builder $query, Model $subject): Builder
    {
        return $query
            ->where($this->qualifyColumn('subject_type'), $subject->getMorphClass())
            ->where($this->qualifyColumn('subject_id'), (string) $subject->getKey());
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    public function isRequestedBy(?Model $user): bool
    {
        return $user !== null
            && $this->requester_id !== null
            && $this->requester_type === $user->getMorphClass()
            && $this->requester_id === (string) $user->getKey();
    }

    public function getWorkflowDefinition(): ?Workflow
    {
        $subject = $this->subject;

        if ($subject instanceof Model && in_array(HasWorkflow::class, class_uses_recursive($subject), true)) {
            return Workflows::for($subject);
        }

        return null;
    }

    public function getTransitionDefinition(): ?Transition
    {
        return $this->getWorkflowDefinition()?->getTransition($this->transition);
    }

    public function getCurrentStepDefinition(): ?ApprovalStep
    {
        return $this->getTransitionDefinition()?->getApprovalStep($this->current_step);
    }
}
