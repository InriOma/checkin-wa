<?php

declare(strict_types=1);

namespace App\Domains\WhatsApp\Policies;

use App\Domains\Identity\Enums\TenantRole;
use App\Domains\Tenancy\Services\TenantContext;
use App\Domains\WhatsApp\Models\WhatsAppChannel;
use App\Models\User;

class WhatsAppChannelPolicy
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    public function viewAny(User $user): bool
    {
        if ($user->is_platform_admin) {
            return true;
        }

        $tenantId = $this->tenantContext->id();

        return $tenantId !== null && $user->hasTenantRole($tenantId, TenantRole::adminRoles());
    }

    public function view(User $user, WhatsAppChannel $channel): bool
    {
        if ($user->is_platform_admin) {
            return true;
        }

        $tenantId = $this->tenantContext->id();

        return $tenantId !== null
            && $channel->isDedicated()
            && $channel->tenant_id === $tenantId
            && $user->hasTenantRole($tenantId, TenantRole::adminRoles());
    }

    public function create(User $user): bool
    {
        if ($user->is_platform_admin) {
            return true;
        }

        $tenantId = $this->tenantContext->id();

        return $tenantId !== null && $user->hasTenantRole($tenantId, TenantRole::CompanyOwner);
    }

    public function update(User $user, WhatsAppChannel $channel): bool
    {
        if ($user->is_platform_admin) {
            return true;
        }

        $tenantId = $this->tenantContext->id();

        return $tenantId !== null
            && $channel->isDedicated()
            && $channel->tenant_id === $tenantId
            && $user->hasTenantRole($tenantId, TenantRole::CompanyOwner);
    }

    public function delete(User $user, WhatsAppChannel $channel): bool
    {
        return $this->update($user, $channel);
    }
}
