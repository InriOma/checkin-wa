<?php

declare(strict_types=1);

namespace App\Domains\Branches\Policies;

use App\Domains\Branches\Models\Branch;
use App\Domains\Identity\Enums\TenantRole;
use App\Domains\Tenancy\Services\TenantContext;
use App\Models\User;

class BranchPolicy
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    public function viewAny(User $user): bool
    {
        $tenantId = $this->tenantContext->id();
        if ($tenantId === null) {
            return false;
        }

        return $user->hasTenantRole($tenantId, TenantRole::cases());
    }

    public function view(User $user, Branch $branch): bool
    {
        $tenantId = $this->tenantContext->id();
        if ($tenantId === null || $branch->tenant_id !== $tenantId) {
            return false;
        }

        if ($user->hasTenantRole($tenantId, [TenantRole::CompanyOwner, TenantRole::HrAdmin, TenantRole::Employee])) {
            return true;
        }

        return $user->canAccessBranch($tenantId, $branch->id);
    }

    public function create(User $user): bool
    {
        $tenantId = $this->tenantContext->id();
        if ($tenantId === null) {
            return false;
        }

        return $user->hasTenantRole($tenantId, TenantRole::adminRoles());
    }

    public function update(User $user, Branch $branch): bool
    {
        $tenantId = $this->tenantContext->id();
        if ($tenantId === null || $branch->tenant_id !== $tenantId) {
            return false;
        }

        return $user->hasTenantRole($tenantId, TenantRole::adminRoles());
    }

    public function delete(User $user, Branch $branch): bool
    {
        $tenantId = $this->tenantContext->id();
        if ($tenantId === null || $branch->tenant_id !== $tenantId) {
            return false;
        }

        return $user->hasTenantRole($tenantId, TenantRole::adminRoles());
    }
}
