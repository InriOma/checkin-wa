<?php

declare(strict_types=1);

namespace App\Domains\Incidents\Policies;

use App\Domains\Identity\Enums\TenantRole;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Tenancy\Services\TenantContext;
use App\Models\User;

class IncidentPolicy
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    public function viewAny(User $user): bool
    {
        $tenantId = $this->tenantContext->id();

        return $tenantId !== null && $user->hasTenantRole($tenantId, TenantRole::managementRoles());
    }

    public function view(User $user, Incident $incident): bool
    {
        $tenantId = $this->tenantContext->id();
        if ($tenantId === null || $incident->tenant_id !== $tenantId) {
            return false;
        }

        if ($user->hasTenantRole($tenantId, TenantRole::adminRoles())) {
            return true;
        }

        if ($user->hasTenantRole($tenantId, TenantRole::BranchManager)) {
            return $incident->branch_id === null || $user->canAccessBranch($tenantId, $incident->branch_id);
        }

        return false;
    }

    public function review(User $user, Incident $incident): bool
    {
        $tenantId = $this->tenantContext->id();
        if ($tenantId === null || $incident->tenant_id !== $tenantId) {
            return false;
        }

        if ($user->hasTenantRole($tenantId, TenantRole::adminRoles())) {
            return true;
        }

        if ($user->hasTenantRole($tenantId, TenantRole::BranchManager)) {
            return $incident->branch_id !== null && $user->canAccessBranch($tenantId, $incident->branch_id);
        }

        return false;
    }
}
