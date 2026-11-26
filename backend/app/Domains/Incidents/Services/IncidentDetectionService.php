<?php

declare(strict_types=1);

namespace App\Domains\Incidents\Services;

use App\Domains\Attendance\Enums\AttendanceStatus;
use App\Domains\Attendance\Models\AttendanceRecord;
use App\Domains\Employees\Models\Employee;
use App\Domains\Incidents\Enums\IncidentStatus;
use App\Domains\Incidents\Enums\IncidentType;
use App\Domains\Incidents\Models\Justification;
use App\Domains\Schedules\Models\ShiftAssignment;
use App\Domains\Schedules\Services\ScheduleEvaluator;
use App\Domains\Tenancy\Models\Tenant;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final class IncidentDetectionService
{
    public function __construct(
        private readonly ScheduleEvaluator $scheduleEvaluator,
        private readonly IncidentRecorderService $recorder,
    ) {}

    /**
     * @return array{absences_created: int, incomplete_created: int}
     */
    public function detectAll(?CarbonInterface $asOfUtc = null, ?int $tenantId = null): array
    {
        $now = CarbonImmutable::instance($asOfUtc ?? now())->utc();

        return [
            'absences_created' => $this->detectAbsences($now, $tenantId),
            'incomplete_created' => $this->detectIncompleteShifts($now, $tenantId),
        ];
    }

    public function detectAbsences(CarbonInterface $asOfUtc, ?int $tenantId = null): int
    {
        $now = CarbonImmutable::instance($asOfUtc)->utc();
        $created = 0;

        $tenantsQuery = Tenant::query()->whereIn('status', ['active', 'trial']);
        if ($tenantId !== null) {
            $tenantsQuery->where('id', $tenantId);
        }

        foreach ($tenantsQuery->cursor() as $tenant) {
            $employees = Employee::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('status', 'active')
                ->get();

            foreach ($employees as $employee) {
                $created += $this->checkEmployeeAbsences($employee, $now);
            }
        }

        return $created;
    }

    public function detectIncompleteShifts(CarbonInterface $asOfUtc, ?int $tenantId = null, int $graceMinutes = 120): int
    {
        $now = CarbonImmutable::instance($asOfUtc)->utc();
        $created = 0;

        $query = AttendanceRecord::withoutGlobalScopes()
            ->with(['schedule', 'branch'])
            ->whereIn('status', [AttendanceStatus::Working->value, AttendanceStatus::OnBreak->value]);

        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }

        foreach ($query->cursor() as $record) {
            $isOverdue = false;
            $workDate = $record->work_date->toDateString();

            if ($record->schedule !== null) {
                $window = $this->scheduleEvaluator->buildShiftWindow($record->schedule, $workDate);
                if ($window !== null && $now->greaterThanOrEqualTo($window['end_utc']->addMinutes($graceMinutes))) {
                    $isOverdue = true;
                }
            } elseif ($now->greaterThanOrEqualTo($record->clock_in_at->copy()->addHours(16))) {
                $isOverdue = true;
            }

            if (! $isOverdue) {
                continue;
            }

            $incident = $this->recorder->recordDailyIncident(
                tenantId: $record->tenant_id,
                employeeId: $record->employee_id,
                incidentDate: $workDate,
                type: IncidentType::IncompleteShift,
                attendanceRecordId: $record->id,
                branchId: $record->branch_id,
                metadata: ['clock_in_at' => $record->clock_in_at->toIso8601String()],
            );

            if ($incident->wasRecentlyCreated) {
                $created++;
            }
        }

        return $created;
    }

    private function checkEmployeeAbsences(Employee $employee, CarbonImmutable $nowUtc): int
    {
        $assignments = ShiftAssignment::withoutGlobalScopes()
            ->with('schedule')
            ->where('tenant_id', $employee->tenant_id)
            ->where('employee_id', $employee->id)
            ->get();

        if ($assignments->isEmpty()) {
            return 0;
        }

        $created = 0;

        foreach ($assignments as $assignment) {
            $schedule = $assignment->schedule;
            if ($schedule === null || $schedule->status !== 'active') {
                continue;
            }

            $tz = $schedule->timezone ?: 'UTC';
            $localToday = $nowUtc->setTimezone($tz)->toDateString();
            $localYesterday = $nowUtc->setTimezone($tz)->subDay()->toDateString();

            foreach ([$localYesterday, $localToday] as $candidateDate) {
                if ($assignment->effective_from->toDateString() > $candidateDate) {
                    continue;
                }
                if ($assignment->effective_until !== null && $assignment->effective_until->toDateString() < $candidateDate) {
                    continue;
                }

                $window = $this->scheduleEvaluator->buildShiftWindow($schedule, $candidateDate);
                if ($window === null) {
                    continue;
                }

                $thresholdInstant = $window['start_utc']->addMinutes((int) $schedule->absence_threshold_minutes);
                if ($nowUtc->lessThan($thresholdInstant)) {
                    continue;
                }

                $hasAttendance = AttendanceRecord::withoutGlobalScopes()
                    ->where('tenant_id', $employee->tenant_id)
                    ->where('employee_id', $employee->id)
                    ->whereDate('work_date', $candidateDate)
                    ->exists();

                if ($hasAttendance) {
                    continue;
                }

                $hasApprovedJustification = Justification::withoutGlobalScopes()
                    ->where('tenant_id', $employee->tenant_id)
                    ->where('employee_id', $employee->id)
                    ->where('status', IncidentStatus::Approved->value)
                    ->whereDate('start_date', '<=', $candidateDate)
                    ->whereDate('end_date', '>=', $candidateDate)
                    ->exists();

                if ($hasApprovedJustification) {
                    continue;
                }

                $incident = $this->recorder->recordDailyIncident(
                    tenantId: $employee->tenant_id,
                    employeeId: $employee->id,
                    incidentDate: $candidateDate,
                    type: IncidentType::Absence,
                    branchId: $employee->default_branch_id,
                    metadata: ['schedule_id' => $schedule->id, 'threshold_minutes' => $schedule->absence_threshold_minutes],
                );

                if ($incident->wasRecentlyCreated) {
                    $created++;
                }
            }
        }

        return $created;
    }
}
