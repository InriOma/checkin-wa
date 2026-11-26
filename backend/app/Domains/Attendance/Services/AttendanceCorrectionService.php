<?php

declare(strict_types=1);

namespace App\Domains\Attendance\Services;

use App\Domains\Attendance\Enums\AttendanceEventType;
use App\Domains\Attendance\Enums\AttendanceSource;
use App\Domains\Attendance\Enums\AttendanceStatus;
use App\Domains\Attendance\Models\AttendanceEvent;
use App\Domains\Attendance\Models\AttendanceRecord;
use App\Domains\Audit\Services\AuditLogger;
use App\Domains\Branches\Models\Branch;
use App\Domains\Schedules\Services\ScheduleEvaluator;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AttendanceCorrectionService
{
    public function __construct(
        private readonly ScheduleEvaluator $scheduleEvaluator,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * Applies an auditable administrative correction without ever mutating historical attendance events.
     *
     * @param  array<string, mixed>  $data
     */
    public function correct(AttendanceRecord $record, array $data, User $actor): AttendanceRecord
    {
        return DB::transaction(function () use ($record, $data, $actor): AttendanceRecord {
            /** @var AttendanceRecord $locked */
            $locked = AttendanceRecord::withoutGlobalScopes()
                ->with('schedule')
                ->where('tenant_id', $record->tenant_id)
                ->where('id', $record->id)
                ->lockForUpdate()
                ->firstOrFail();

            $oldSnapshot = [
                'branch_id' => $locked->branch_id,
                'status' => $locked->status->value,
                'clock_in_at' => $locked->clock_in_at?->toIso8601String(),
                'clock_out_at' => $locked->clock_out_at?->toIso8601String(),
                'total_worked_minutes' => $locked->total_worked_minutes,
                'late_minutes' => $locked->late_minutes,
                'early_departure_minutes' => $locked->early_departure_minutes,
            ];

            if (isset($data['branch_id'])) {
                $branchExists = Branch::withoutGlobalScopes()
                    ->where('tenant_id', $locked->tenant_id)
                    ->where('id', (int) $data['branch_id'])
                    ->exists();
                if (! $branchExists) {
                    throw ValidationException::withMessages([
                        'branch_id' => ['Selected branch does not belong to the active tenant.'],
                    ]);
                }
            }

            $clockInAt = isset($data['clock_in_at'])
                ? CarbonImmutable::parse((string) $data['clock_in_at'])->utc()
                : CarbonImmutable::instance($locked->clock_in_at)->utc();

            $clockOutAt = array_key_exists('clock_out_at', $data)
                ? ($data['clock_out_at'] !== null ? CarbonImmutable::parse((string) $data['clock_out_at'])->utc() : null)
                : ($locked->clock_out_at !== null ? CarbonImmutable::instance($locked->clock_out_at)->utc() : null);

            if ($clockOutAt !== null && $clockOutAt->lessThan($clockInAt)) {
                throw ValidationException::withMessages([
                    'clock_out_at' => ['clock_out_at cannot be earlier than clock_in_at.'],
                ]);
            }

            $newStatus = isset($data['status'])
                ? AttendanceStatus::from((string) $data['status'])
                : ($clockOutAt !== null ? AttendanceStatus::Closed : $locked->status);

            $workedMinutes = $locked->total_worked_minutes;
            if ($clockOutAt !== null) {
                $gross = max(0, (int) $clockInAt->diffInMinutes($clockOutAt));
                $workedMinutes = max(0, $gross - (int) $locked->total_break_minutes);
            }

            $workDate = $locked->work_date->toDateString();
            $lateMinutes = $locked->schedule !== null
                ? $this->scheduleEvaluator->calculateLateMinutes($locked->schedule, $clockInAt, $workDate)
                : $locked->late_minutes;

            $earlyDepartureMinutes = ($locked->schedule !== null && $clockOutAt !== null)
                ? $this->scheduleEvaluator->calculateEarlyDepartureMinutes($locked->schedule, $clockOutAt, $workDate)
                : 0;

            $locked->update([
                'branch_id' => $data['branch_id'] ?? $locked->branch_id,
                'status' => $newStatus,
                'clock_in_at' => $clockInAt,
                'clock_out_at' => $clockOutAt,
                'total_worked_minutes' => $workedMinutes,
                'late_minutes' => $lateMinutes,
                'early_departure_minutes' => $earlyDepartureMinutes,
            ]);

            $newSnapshot = [
                'branch_id' => $locked->branch_id,
                'status' => $locked->status->value,
                'clock_in_at' => $locked->clock_in_at?->toIso8601String(),
                'clock_out_at' => $locked->clock_out_at?->toIso8601String(),
                'total_worked_minutes' => $locked->total_worked_minutes,
                'late_minutes' => $locked->late_minutes,
                'early_departure_minutes' => $locked->early_departure_minutes,
            ];

            $now = now()->utc();
            AttendanceEvent::withoutGlobalScopes()->create([
                'tenant_id' => $locked->tenant_id,
                'attendance_record_id' => $locked->id,
                'employee_id' => $locked->employee_id,
                'branch_id' => $locked->branch_id,
                'event_type' => AttendanceEventType::Correction,
                'source' => AttendanceSource::AdminCorrection,
                'received_at' => $now,
                'event_at' => $now,
                'notes' => (string) $data['reason'],
                'metadata' => [
                    'corrected_by_user_id' => $actor->id,
                    'old' => $oldSnapshot,
                    'new' => $newSnapshot,
                ],
                'created_at' => $now,
            ]);

            $this->auditLogger->log(
                'attendance.corrected',
                $locked,
                $actor,
                $oldSnapshot,
                array_merge($newSnapshot, ['reason' => $data['reason']]),
            );

            return $locked->refresh()->load(['employee', 'branch', 'events', 'breaks']);
        });
    }
}
