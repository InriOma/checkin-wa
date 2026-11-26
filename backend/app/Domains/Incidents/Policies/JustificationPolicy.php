<?php

declare(strict_types=1);

namespace App\Domains\Incidents\Policies;

use App\Domains\Identity\Enums\TenantRole;
use App\Domains\Incidents\Models\Justification;
use App\Domains\Tenancy\Services\TenantContext;
use App\Models\User;

class JustificationPolicy
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    public function viewAny(User $user): bool
    {
        $tenantId = $this->tenantContext->id();

        return $tenantId !== null && $user->hasTenantRole($tenantId, TenantRole::managementRoles());
    }

    public function view(User $user, Justification $justification): bool
    {
        $tenantId = $this->tenantContext->id();

        return $tenantId !== null
            && $justification->tenant_id === $tenantId
            && $user->hasTenantRole($tenantId, TenantRole::managementRoles());
    }

    public function create(User $user): bool
    {
        $tenantId = $this->tenantContext->id();

        return $tenantId !== null && $user->hasTenantRole($tenantId, TenantRole::adminRoles());
    }

    public function review(User $user, Justification $justification): bool
    {
        $tenantId = $this->tenantContext->id();

        return $tenantId !== null
            && $justification->tenant_id === $tenantId
            && $user->hasTenantRole($tenantId, TenantRole::adminRoles());
    }
}
