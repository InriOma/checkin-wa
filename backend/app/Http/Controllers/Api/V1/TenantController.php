<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domains\Audit\Services\AuditLogger;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Services\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\StoreTenantRequest;
use App\Http\Requests\V1\UpdateTenantRequest;
use App\Http\Resources\V1\TenantResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class TenantController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Tenant::class);

        $tenants = Tenant::query()
            ->orderBy('name')
            ->paginate(min((int) $request->integer('per_page', 20), 100));

        return TenantResource::collection($tenants);
    }

    public function store(StoreTenantRequest $request): JsonResponse
    {
        Gate::authorize('create', Tenant::class);

        $tenant = Tenant::query()->create($request->validated());
        $this->auditLogger->log('tenant.created', $tenant, $request->user(), null, $tenant->toArray(), 'user', $request);

        return (new TenantResource($tenant))
            ->response()
            ->setStatusCode(201);
    }

    public function showCurrent(): TenantResource
    {
        $tenant = $this->tenantContext->tenant();
        abort_if($tenant === null, 404, 'No active tenant.');
        Gate::authorize('view', $tenant);

        return new TenantResource($tenant);
    }

    public function updateCurrent(UpdateTenantRequest $request): TenantResource
    {
        $tenant = $this->tenantContext->tenant();
        abort_if($tenant === null, 404, 'No active tenant.');
        Gate::authorize('update', $tenant);

        $oldValues = $tenant->only(['name', 'timezone', 'status']);
        $tenant->update($request->validated());
        $this->auditLogger->log('tenant.updated', $tenant, $request->user(), $oldValues, $tenant->only(['name', 'timezone', 'status']), 'user', $request);

        return new TenantResource($tenant->refresh());
    }
}
