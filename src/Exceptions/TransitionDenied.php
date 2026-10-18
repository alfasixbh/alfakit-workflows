<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitWorkflows\Exceptions;

use RuntimeException;

/**
 * A transition or an approval decision was refused. `reason` is one of the constants;
 * the message is translated and safe to show to the user.
 */
class TransitionDenied extends RuntimeException
{
    public const UNKNOWN = 'unknown';

    public const INVALID_STATE = 'invalid_state';

    public const UNAUTHORIZED = 'unauthorized';

    public const GUARD = 'guard';

    public const AWAITING_APPROVAL = 'awaiting_approval';

    public const NO_APPROVERS = 'no_approvers';

    public const NOT_PENDING = 'not_pending';

    public const COMMENT_REQUIRED = 'comment_required';

    public const INVALID_DELEGATE = 'invalid_delegate';

    final public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    /**
     * @param  array<string, string>  $replace
     */
    public static function because(string $reason, ?string $message = null, array $replace = []): static
    {
        return new static($reason, $message ?? (string) __('alfakit-workflows::workflows.errors.'.$reason, $replace));
    }
}
