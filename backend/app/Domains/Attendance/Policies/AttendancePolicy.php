<?php

declare(strict_types=1);

namespace App\Domains\Attendance\Policies;

use App\Domains\Attendance\Models\AttendanceRecord;
use App\Domains\Identity\Enums\TenantRole;
use App\Domains\Tenancy\Services\TenantContext;
use App\Models\User;

class AttendancePolicy
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    public function viewAny(User $user): bool
    {
        $tenantId = $this->tenantContext->id();

        return $tenantId !== null && $user->hasTenantRole($tenantId, TenantRole::managementRoles());
    }

    public function view(User $user, AttendanceRecord $record): bool
    {
        $tenantId = $this->tenantContext->id();
        if ($tenantId === null || $record->tenant_id !== $tenantId) {
            return false;
        }

        if ($user->hasTenantRole($tenantId, TenantRole::adminRoles())) {
            return true;
        }

        if ($user->hasTenantRole($tenantId, TenantRole::BranchManager)) {
            return $record->branch_id === null || $user->canAccessBranch($tenantId, $record->branch_id);
        }

        return $record->employee?->user_id === $user->id;
    }

    public function correct(User $user, AttendanceRecord $record): bool
    {
        $tenantId = $this->tenantContext->id();
        if ($tenantId === null || $record->tenant_id !== $tenantId) {
            return false;
        }

        return $user->hasTenantRole($tenantId, TenantRole::adminRoles());
    }
}
