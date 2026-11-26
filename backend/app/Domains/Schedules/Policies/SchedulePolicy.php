<?php

declare(strict_types=1);

namespace App\Domains\Schedules\Policies;

use App\Domains\Identity\Enums\TenantRole;
use App\Domains\Schedules\Models\Schedule;
use App\Domains\Tenancy\Services\TenantContext;
use App\Models\User;

class SchedulePolicy
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

    public function view(User $user, Schedule $schedule): bool
    {
        $tenantId = $this->tenantContext->id();
        if ($tenantId === null || $schedule->tenant_id !== $tenantId) {
            return false;
        }

        return $user->hasTenantRole($tenantId, TenantRole::cases());
    }

    public function create(User $user): bool
    {
        $tenantId = $this->tenantContext->id();
        if ($tenantId === null) {
            return false;
        }

        return $user->hasTenantRole($tenantId, TenantRole::adminRoles());
    }

    public function update(User $user, Schedule $schedule): bool
    {
        $tenantId = $this->tenantContext->id();
        if ($tenantId === null || $schedule->tenant_id !== $tenantId) {
            return false;
        }

        return $user->hasTenantRole($tenantId, TenantRole::adminRoles());
    }

    public function delete(User $user, Schedule $schedule): bool
    {
        $tenantId = $this->tenantContext->id();
        if ($tenantId === null || $schedule->tenant_id !== $tenantId) {
            return false;
        }

        return $user->hasTenantRole($tenantId, TenantRole::adminRoles());
    }
}
