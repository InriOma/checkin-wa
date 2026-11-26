<?php

declare(strict_types=1);

namespace App\Domains\Branches\Services;

use App\Domains\Audit\Services\AuditLogger;
use App\Domains\Billing\Services\PlanLimitChecker;
use App\Domains\Branches\Models\Branch;
use App\Domains\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class BranchService
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly PlanLimitChecker $planLimitChecker,
        private readonly AuditLogger $auditLogger,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(array $data, ?User $actor = null): Branch
    {
        $tenant = $this->tenantContext->tenant();
        if ($tenant === null) {
            throw new \RuntimeException('Active tenant is required.');
        }

        $this->planLimitChecker->ensureCanCreateBranch($tenant);

        return DB::transaction(function () use ($data, $actor, $tenant): Branch {
            $branch = Branch::query()->create([
                'tenant_id' => $tenant->id,
                'code' => $data['code'],
                'name' => $data['name'],
                'address' => $data['address'] ?? null,
                'latitude' => $data['latitude'],
                'longitude' => $data['longitude'],
                'geofence_radius_meters' => $data['geofence_radius_meters'] ?? 100,
                'timezone' => $data['timezone'] ?? $tenant->timezone,
                'status' => $data['status'] ?? 'active',
            ]);

            $this->auditLogger->log('branch.created', $branch, $actor, null, $branch->toArray());

            return $branch;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(Branch $branch, array $data, ?User $actor = null): Branch
    {
        return DB::transaction(function () use ($branch, $data, $actor): Branch {
            $oldValues = $branch->only([
                'code', 'name', 'address', 'latitude', 'longitude', 'geofence_radius_meters', 'timezone', 'status',
            ]);

            $branch->update($data);

            $this->auditLogger->log(
                'branch.updated',
                $branch,
                $actor,
                $oldValues,
                $branch->only(array_keys($oldValues)),
            );

            return $branch->refresh();
        });
    }

    public function delete(Branch $branch, ?User $actor = null): void
    {
        DB::transaction(function () use ($branch, $actor): void {
            $oldValues = $branch->only(['id', 'code', 'name', 'status']);
            $branch->delete();
            $this->auditLogger->log('branch.deleted', $branch, $actor, $oldValues, null);
        });
    }
}
