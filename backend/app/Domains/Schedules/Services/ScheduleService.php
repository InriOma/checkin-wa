<?php

declare(strict_types=1);

namespace App\Domains\Schedules\Services;

use App\Domains\Audit\Services\AuditLogger;
use App\Domains\Employees\Models\Employee;
use App\Domains\Schedules\Models\Schedule;
use App\Domains\Schedules\Models\ShiftAssignment;
use App\Domains\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ScheduleService
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AuditLogger $auditLogger,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(array $data, ?User $actor = null): Schedule
    {
        $tenant = $this->tenantContext->tenant();
        if ($tenant === null) {
            throw new \RuntimeException('Active tenant is required.');
        }

        return DB::transaction(function () use ($data, $actor, $tenant): Schedule {
            $schedule = Schedule::query()->create([
                'tenant_id' => $tenant->id,
                'name' => $data['name'],
                'timezone' => $data['timezone'] ?? $tenant->timezone,
                'work_days' => array_values(array_unique(array_map('intval', $data['work_days']))),
                'start_time' => $data['start_time'],
                'end_time' => $data['end_time'],
                'late_tolerance_minutes' => $data['late_tolerance_minutes'] ?? 10,
                'absence_threshold_minutes' => $data['absence_threshold_minutes'] ?? 120,
                'break_duration_minutes' => $data['break_duration_minutes'] ?? 30,
                'max_breaks_per_shift' => $data['max_breaks_per_shift'] ?? 2,
                'status' => $data['status'] ?? 'active',
            ]);

            $this->auditLogger->log('schedule.created', $schedule, $actor, null, $schedule->toArray());

            return $schedule;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(Schedule $schedule, array $data, ?User $actor = null): Schedule
    {
        return DB::transaction(function () use ($schedule, $data, $actor): Schedule {
            $oldValues = $schedule->only([
                'name', 'timezone', 'work_days', 'start_time', 'end_time',
                'late_tolerance_minutes', 'absence_threshold_minutes',
                'break_duration_minutes', 'max_breaks_per_shift', 'status',
            ]);

            if (isset($data['work_days'])) {
                $data['work_days'] = array_values(array_unique(array_map('intval', $data['work_days'])));
            }

            $schedule->update($data);

            $this->auditLogger->log(
                'schedule.updated',
                $schedule,
                $actor,
                $oldValues,
                $schedule->only(array_keys($oldValues)),
            );

            return $schedule->refresh();
        });
    }

    public function assignToEmployee(
        Schedule $schedule,
        int $employeeId,
        string $effectiveFrom,
        ?string $effectiveUntil = null,
        ?User $actor = null,
    ): ShiftAssignment {
        $tenantId = $this->tenantContext->requireId();

        if ($schedule->tenant_id !== $tenantId) {
            throw ValidationException::withMessages([
                'schedule_id' => ['Schedule does not belong to the active tenant.'],
            ]);
        }

        $employee = Employee::query()
            ->where('tenant_id', $tenantId)
            ->find($employeeId);

        if ($employee === null) {
            throw ValidationException::withMessages([
                'employee_id' => ['Employee does not belong to the active tenant.'],
            ]);
        }

        return DB::transaction(function () use ($tenantId, $schedule, $employee, $effectiveFrom, $effectiveUntil, $actor): ShiftAssignment {
            $assignment = ShiftAssignment::query()->create([
                'tenant_id' => $tenantId,
                'employee_id' => $employee->id,
                'schedule_id' => $schedule->id,
                'effective_from' => $effectiveFrom,
                'effective_until' => $effectiveUntil,
            ]);

            $this->auditLogger->log('schedule.assigned', $assignment, $actor, null, $assignment->toArray());

            return $assignment->load('employee', 'schedule');
        });
    }

    public function delete(Schedule $schedule, ?User $actor = null): void
    {
        DB::transaction(function () use ($schedule, $actor): void {
            $oldValues = $schedule->only(['id', 'name', 'status']);
            $schedule->delete();
            $this->auditLogger->log('schedule.deleted', $schedule, $actor, $oldValues, null);
        });
    }
}
