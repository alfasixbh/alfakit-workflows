<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitWorkflows\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * The audit trail of a record's workflow: every transition, request, decision,
 * delegation and escalation, with who did it, when and their comment. Append-only.
 *
 * @property int $id
 * @property string $workflow
 * @property string $subject_type
 * @property string $subject_id
 * @property string|null $tenant_type
 * @property string|null $tenant_id
 * @property string $event
 * @property string|null $transition
 * @property string|null $from_state
 * @property string|null $to_state
 * @property string|null $causer_type
 * @property string|null $causer_id
 * @property int|null $request_id
 * @property string|null $comment
 * @property array<string, mixed>|null $data
 * @property array<string, mixed>|null $properties
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property-read Model|null $subject
 * @property-read Model|null $causer
 * @property-read ApprovalRequest|null $request
 */
class WorkflowLog extends Model
{
    public const CREATED = 'created';

    public const TRANSITIONED = 'transitioned';

    public const REQUESTED = 'requested';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const DELEGATED = 'delegated';

    public const REASSIGNED = 'reassigned';

    public const ESCALATED = 'escalated';

    public const CANCELLED = 'cancelled';

    public const UPDATED_AT = null;

    protected $table = 'alfakit_workflow_logs';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'properties' => 'array',
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
    public function causer(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<ApprovalRequest, $this>
     */
    public function request(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class, 'request_id');
    }

    /**
     * @param  Builder<WorkflowLog>  $query
     * @return Builder<WorkflowLog>
     */
    public function scopeForSubject(Builder $query, Model $subject): Builder
    {
        return $query
            ->where($this->qualifyColumn('subject_type'), $subject->getMorphClass())
            ->where($this->qualifyColumn('subject_id'), (string) $subject->getKey());
    }
}
