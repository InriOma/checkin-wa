<?php

declare(strict_types=1);

namespace App\Domains\Employees\Services;

use App\Domains\Audit\Services\AuditLogger;
use App\Domains\Billing\Services\PlanLimitChecker;
use App\Domains\Branches\Models\Branch;
use App\Domains\Employees\Models\Employee;
use App\Domains\Employees\Models\EmployeeBranchAssignment;
use App\Domains\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class EmployeeService
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly PlanLimitChecker $planLimitChecker,
        private readonly AuditLogger $auditLogger,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(array $data, ?User $actor = null): Employee
    {
        $tenant = $this->tenantContext->tenant();
        if ($tenant === null) {
            throw new \RuntimeException('Active tenant is required.');
        }

        $this->planLimitChecker->ensureCanCreateEmployee($tenant);
        $this->ensureBranchBelongsToTenant($data['default_branch_id'] ?? null, $tenant->id);

        return DB::transaction(function () use ($data, $actor, $tenant): Employee {
            $employee = Employee::query()->create([
                'tenant_id' => $tenant->id,
                'user_id' => $data['user_id'] ?? null,
                'employee_code' => $data['employee_code'],
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'email' => $data['email'] ?? null,
                'default_branch_id' => $data['default_branch_id'] ?? null,
                'status' => $data['status'] ?? 'active',
                'hired_at' => $data['hired_at'] ?? null,
            ]);

            if (! empty($data['branch_ids']) && is_array($data['branch_ids'])) {
                $this->syncBranchAssignments($employee, $data['branch_ids'], $data['effective_from'] ?? now()->toDateString(), null, $actor);
            } elseif ($employee->default_branch_id !== null) {
                EmployeeBranchAssignment::query()->create([
                    'tenant_id' => $tenant->id,
                    'employee_id' => $employee->id,
                    'branch_id' => $employee->default_branch_id,
                    'effective_from' => $data['hired_at'] ?? now()->toDateString(),
                    'effective_until' => null,
                ]);
            }

            $this->auditLogger->log('employee.created', $employee, $actor, null, $employee->toArray());

            return $employee->load('defaultBranch', 'branchAssignments.branch');
        });
    }

    /** @param array<string, mixed> $data */
    public function update(Employee $employee, array $data, ?User $actor = null): Employee
    {
        $tenantId = $this->tenantContext->requireId();
        if (array_key_exists('default_branch_id', $data)) {
            $this->ensureBranchBelongsToTenant($data['default_branch_id'], $tenantId);
        }

        return DB::transaction(function () use ($employee, $data, $actor): Employee {
            $oldValues = $employee->only([
                'employee_code', 'first_name', 'last_name', 'email', 'default_branch_id', 'status', 'hired_at',
            ]);

            $employee->update($data);

            $this->auditLogger->log(
                'employee.updated',
                $employee,
                $actor,
                $oldValues,
                $employee->only(array_keys($oldValues)),
            );

            return $employee->refresh()->load('defaultBranch', 'branchAssignments.branch');
        });
    }

    /** @param list<int> $branchIds */
    public function syncBranchAssignments(
        Employee $employee,
        array $branchIds,
        string $effectiveFrom,
        ?string $effectiveUntil = null,
        ?User $actor = null,
    ): Employee {
        $tenantId = $this->tenantContext->requireId();
        $uniqueBranchIds = array_values(array_unique(array_map('intval', $branchIds)));

        $validCount = Branch::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $uniqueBranchIds)
            ->count();

        if ($validCount !== count($uniqueBranchIds)) {
            throw ValidationException::withMessages([
                'branch_ids' => ['One or more branches do not belong to the active tenant.'],
            ]);
        }

        return DB::transaction(function () use ($employee, $uniqueBranchIds, $effectiveFrom, $effectiveUntil, $tenantId, $actor): Employee {
            EmployeeBranchAssignment::query()
                ->where('tenant_id', $tenantId)
                ->where('employee_id', $employee->id)
                ->delete();

            foreach ($uniqueBranchIds as $branchId) {
                EmployeeBranchAssignment::query()->create([
                    'tenant_id' => $tenantId,
                    'employee_id' => $employee->id,
                    'branch_id' => $branchId,
                    'effective_from' => $effectiveFrom,
                    'effective_until' => $effectiveUntil,
                ]);
            }

            $this->auditLogger->log('employee.branches_assigned', $employee, $actor, null, [
                'branch_ids' => $uniqueBranchIds,
                'effective_from' => $effectiveFrom,
                'effective_until' => $effectiveUntil,
            ]);

            return $employee->load('branchAssignments.branch');
        });
    }

    public function delete(Employee $employee, ?User $actor = null): void
    {
        DB::transaction(function () use ($employee, $actor): void {
            $oldValues = $employee->only(['id', 'employee_code', 'status']);
            $employee->delete();
            $this->auditLogger->log('employee.deleted', $employee, $actor, $oldValues, null);
        });
    }

    private function ensureBranchBelongsToTenant(mixed $branchId, int $tenantId): void
    {
        if ($branchId === null) {
            return;
        }

        $exists = Branch::query()
            ->where('tenant_id', $tenantId)
            ->where('id', (int) $branchId)
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages([
                'default_branch_id' => ['The selected default branch does not belong to the active tenant.'],
            ]);
        }
    }
}
