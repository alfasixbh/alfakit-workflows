<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitWorkflows\Events;

use AlfaSix\AlfakitWorkflows\Models\ApprovalRequest;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Collection;

/**
 * Approvals were asked for a step of a request (the first step when a transition needs
 * approval, then each following step).
 */
class ApprovalRequested implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @param  Collection<int, Model>  $approvers
     */
    public function __construct(
        public readonly ApprovalRequest $request,
        public readonly Model $record,
        public readonly string $step,
        public readonly Collection $approvers,
        public readonly ?Model $requester,
    ) {}
}
