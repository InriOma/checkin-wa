<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Services\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenant
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $tenant = $this->resolveRequestedTenant($request);
        if ($tenant === null) {
            return response()->json([
                'message' => 'A valid active tenant must be selected via X-Tenant-Id or X-Tenant-Code.',
            ], 422);
        }

        if (! $tenant->isActive()) {
            return response()->json([
                'message' => 'The selected tenant is not active.',
            ], 403);
        }

        if (! $user->is_platform_admin && $user->membershipFor($tenant) === null) {
            return response()->json([
                'message' => 'You do not have an active membership in this tenant.',
            ], 403);
        }

        return $this->tenantContext->runAsTenant($tenant, fn () => $next($request));
    }

    private function resolveRequestedTenant(Request $request): ?Tenant
    {
        $user = $request->user();
        $tenantHeader = $request->header('X-Tenant-Id');
        $tenantCode = $request->header('X-Tenant-Code');

        if ($tenantHeader !== null && $tenantHeader !== '') {
            if (Str::isUuid($tenantHeader)) {
                return Tenant::query()->where('public_id', $tenantHeader)->first();
            }

            if (ctype_digit($tenantHeader)) {
                return Tenant::query()->find((int) $tenantHeader);
            }

            return null;
        }

        if ($tenantCode !== null && $tenantCode !== '') {
            return Tenant::query()->where('code', strtoupper(trim($tenantCode)))->first();
        }

        $memberships = $user->memberships()
            ->with('tenant')
            ->where('status', 'active')
            ->limit(2)
            ->get();

        if ($memberships->count() === 1) {
            return $memberships->first()?->tenant;
        }

        return null;
    }
}
