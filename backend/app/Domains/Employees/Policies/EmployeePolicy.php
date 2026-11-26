<?php

declare(strict_types=1);

namespace App\Domains\Employees\Policies;

use App\Domains\Employees\Models\Employee;
use App\Domains\Identity\Enums\TenantRole;
use App\Domains\Tenancy\Services\TenantContext;
use App\Models\User;

class EmployeePolicy
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

        return $user->hasTenantRole($tenantId, TenantRole::managementRoles());
    }

    public function view(User $user, Employee $employee): bool
    {
        $tenantId = $this->tenantContext->id();
        if ($tenantId === null || $employee->tenant_id !== $tenantId) {
            return false;
        }

        if ($user->hasTenantRole($tenantId, TenantRole::adminRoles())) {
            return true;
        }

        if ($user->hasTenantRole($tenantId, TenantRole::BranchManager)) {
            if ($employee->default_branch_id !== null && $user->canAccessBranch($tenantId, $employee->default_branch_id)) {
                return true;
            }

            return $employee->branchAssignments()
                ->get()
                ->contains(fn ($assignment): bool => $user->canAccessBranch($tenantId, $assignment->branch_id));
        }

        return $employee->user_id !== null && $employee->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        $tenantId = $this->tenantContext->id();
        if ($tenantId === null) {
            return false;
        }

        return $user->hasTenantRole($tenantId, TenantRole::adminRoles());
    }

    public function update(User $user, Employee $employee): bool
    {
        $tenantId = $this->tenantContext->id();
        if ($tenantId === null || $employee->tenant_id !== $tenantId) {
            return false;
        }

        return $user->hasTenantRole($tenantId, TenantRole::adminRoles());
    }

    public function delete(User $user, Employee $employee): bool
    {
        $tenantId = $this->tenantContext->id();
        if ($tenantId === null || $employee->tenant_id !== $tenantId) {
            return false;
        }

        return $user->hasTenantRole($tenantId, TenantRole::adminRoles());
    }
}
