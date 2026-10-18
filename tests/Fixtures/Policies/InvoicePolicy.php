<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitWorkflows\Tests\Fixtures\Policies;

use AlfaSix\AlfakitWorkflows\Tests\Fixtures\Models\Invoice;
use AlfaSix\AlfakitWorkflows\Tests\Fixtures\Models\WfUser;

class InvoicePolicy
{
    public function viewAny(WfUser $user): bool
    {
        return true;
    }

    public function view(WfUser $user, Invoice $invoice): bool
    {
        return $invoice->number !== 'SECRET';
    }

    public function update(WfUser $user, Invoice $invoice): bool
    {
        return $user->role !== 'viewer';
    }

    public function void(WfUser $user, Invoice $invoice): bool
    {
        return $user->role === 'admin';
    }

    public function reassignApprovals(WfUser $user, Invoice $invoice): bool
    {
        return $user->role === 'admin';
    }
}
