<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Policies;

use App\Domains\Identity\Enums\TenantRole;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;

class TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_platform_admin;
    }

    public function view(User $user, Tenant $tenant): bool
    {
        return $user->is_platform_admin || $user->membershipFor($tenant) !== null;
    }

    public function create(User $user): bool
    {
        return $user->is_platform_admin;
    }

    public function update(User $user, Tenant $tenant): bool
    {
        return $user->hasTenantRole($tenant, TenantRole::CompanyOwner);
    }
}
