<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitWorkflows\Tests\Fixtures\Models;

use AlfaSix\Alfakit\Tests\Fixtures\Models\TenantUser;

class WfUser extends TenantUser
{
    protected $table = 'users';

    public function hasRole(string $role): bool
    {
        return $this->getAttribute('role') === $role;
    }
}
