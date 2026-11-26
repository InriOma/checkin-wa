<?php

declare(strict_types=1);

namespace App\Domains\Audit\Services;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

final class AuditLogger
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    public function log(
        string $action,
        Model $auditable,
        ?User $actor = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        string $actorType = 'user',
        ?Request $request = null,
    ): AuditLog {
        $tenantId = $auditable->getAttribute('tenant_id')
            ?? ($auditable instanceof Tenant ? $auditable->id : $this->tenantContext->id());

        $req = $request ?? (app()->bound('request') ? request() : null);

        return AuditLog::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId,
            'actor_user_id' => $actor?->id,
            'actor_type' => $actorType,
            'action' => $action,
            'auditable_type' => $auditable::class,
            'auditable_id' => (int) $auditable->getKey(),
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => $req?->ip(),
            'user_agent' => $req?->userAgent() ? mb_substr((string) $req->userAgent(), 0, 255) : null,
            'created_at' => now()->utc(),
        ]);
    }
}
