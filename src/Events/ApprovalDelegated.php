<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitWorkflows\Events;

use AlfaSix\AlfakitWorkflows\Models\Approval;
use AlfaSix\AlfakitWorkflows\Models\ApprovalRequest;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A pending approval was handed to someone else: delegated by its approver, reassigned by
 * a manager (`$reassigned`) or escalated by `alfakit-workflows:process` (`$escalated`).
 */
class ApprovalDelegated implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly ApprovalRequest $request,
        public readonly Approval $from,
        public readonly Approval $to,
        public readonly ?Model $by,
        public readonly bool $reassigned = false,
        public readonly bool $escalated = false,
    ) {}
}
