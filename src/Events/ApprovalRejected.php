<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitWorkflows\Events;

use AlfaSix\AlfakitWorkflows\Models\Approval;
use AlfaSix\AlfakitWorkflows\Models\ApprovalRequest;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An approver rejected the request; it is closed.
 */
class ApprovalRejected implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly ApprovalRequest $request,
        public readonly Approval $approval,
        public readonly Model $record,
        public readonly Model $approver,
        public readonly ?string $comment = null,
    ) {}
}
