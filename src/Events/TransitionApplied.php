<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitWorkflows\Events;

use AlfaSix\AlfakitWorkflows\Models\ApprovalRequest;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A record moved to a new state (directly, or once its approval completed). Dispatched
 * after the transaction committed.
 */
class TransitionApplied implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public readonly Model $record,
        public readonly string $workflow,
        public readonly string $transition,
        public readonly ?string $from,
        public readonly string $to,
        public readonly ?Model $user,
        public readonly ?string $comment = null,
        public readonly array $data = [],
        public readonly ?ApprovalRequest $request = null,
    ) {}
}
