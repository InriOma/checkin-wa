<?php

declare(strict_types=1);

namespace App\Domains\Attendance\Services;

use App\Domains\Attendance\Data\AttendanceActionResult;
use App\Domains\Attendance\Enums\AttendanceEventType;
use App\Domains\Attendance\Enums\AttendanceSource;
use App\Domains\Attendance\Enums\AttendanceStatus;
use App\Domains\Attendance\Events\AttendanceGeofenceRejected;
use App\Domains\Attendance\Models\AttendanceBreak;
use App\Domains\Attendance\Models\AttendanceEvent;
use App\Domains\Attendance\Models\AttendanceRecord;
use App\Domains\Employees\Models\Employee;
use App\Domains\Geolocation\Services\GeofenceEvaluator;
use App\Domains\Incidents\Enums\IncidentType;
use App\Domains\Incidents\Services\IncidentRecorderService;
use App\Domains\Schedules\Models\Schedule;
use App\Domains\Schedules\Models\ShiftAssignment;
use App\Domains\Schedules\Services\ScheduleEvaluator;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

final class AttendanceEngine
{
    public function __construct(
        private readonly GeofenceEvaluator $geofenceEvaluator,
        private readonly ScheduleEvaluator $scheduleEvaluator,
        private readonly IncidentRecorderService $incidentRecorder,
    ) {}

    public function getCurrentState(Employee $employee): AttendanceStatus
    {
        $open = $this->findOpenRecord($employee, lock: false);

        return $open?->status ?? AttendanceStatus::NoShiftOpen;
    }

    public function canStartClockIn(Employee $employee): AttendanceActionResult
    {
        $state = $this->getCurrentState($employee);

        if ($state !== AttendanceStatus::NoShiftOpen) {
            return new AttendanceActionResult(
                ok: false,
                code: 'already_clocked_in',
                message: 'Ya tienes una jornada abierta en curso.',
                record: $this->findOpenRecord($employee, lock: false),
            );
        }

        return new AttendanceActionResult(ok: true, code: 'ok', message: 'Listo para registrar entrada.');
    }

    public function canStartClockOut(Employee $employee): AttendanceActionResult
    {
        $open = $this->findOpenRecord($employee, lock: false);

        if ($open === null) {
            return new AttendanceActionResult(
                ok: false,
                code: 'no_open_shift',
                message: 'No tienes una jornada abierta para registrar salida.',
            );
        }

        if ($open->status === AttendanceStatus::OnBreak) {
            return new AttendanceActionResult(
                ok: false,
                code: 'break_still_open',
                message: 'Debes terminar tu descanso antes de registrar la salida.',
                record: $open,
            );
        }

        return new AttendanceActionResult(ok: true, code: 'ok', message: 'Listo para registrar salida.', record: $open);
    }

    public function clockIn(
        Employee $employee,
        float $latitude,
        float $longitude,
        AttendanceSource $source,
        ?int $whatsappChannelId = null,
        ?string $wamid = null,
        ?CarbonInterface $receivedAt = null,
        ?CarbonInterface $eventAt = null,
    ): AttendanceActionResult {
        $now = ($eventAt ?? now())->utc();
        $received = ($receivedAt ?? $now)->utc();

        return DB::transaction(function () use ($employee, $latitude, $longitude, $source, $whatsappChannelId, $wamid, $received, $now): AttendanceActionResult {
            $lockedEmployee = Employee::withoutGlobalScopes()
                ->where('tenant_id', $employee->tenant_id)
                ->where('id', $employee->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($wamid !== null) {
                $existingEvent = AttendanceEvent::withoutGlobalScopes()
                    ->where('tenant_id', $lockedEmployee->tenant_id)
                    ->where('wamid', $wamid)
                    ->where('event_type', AttendanceEventType::ClockIn->value)
                    ->first();

                if ($existingEvent !== null) {
                    return new AttendanceActionResult(
                        ok: true,
                        code: 'duplicate_event',
                        message: 'Entrada ya procesada.',
                        record: $existingEvent->record,
                        event: $existingEvent,
                    );
                }
            }

            $openRecord = $this->findOpenRecord($lockedEmployee, lock: true);
            if ($openRecord !== null) {
                return new AttendanceActionResult(
                    ok: false,
                    code: 'already_clocked_in',
                    message: 'Ya tienes una jornada abierta. Registra tu salida antes de una nueva entrada.',
                    record: $openRecord,
                );
            }

            $geofence = $this->geofenceEvaluator->evaluate($lockedEmployee, $latitude, $longitude, $now);

            if (! $geofence->passed || $geofence->branch === null) {
                $rejectedEvent = AttendanceEvent::withoutGlobalScopes()->create([
                    'tenant_id' => $lockedEmployee->tenant_id,
                    'attendance_record_id' => null,
                    'employee_id' => $lockedEmployee->id,
                    'branch_id' => $geofence->branch?->id,
                    'event_type' => AttendanceEventType::RejectedGeofence,
                    'source' => $source,
                    'whatsapp_channel_id' => $whatsappChannelId,
                    'wamid' => $wamid,
                    'received_at' => $received,
                    'event_at' => $now,
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                    'distance_meters' => $geofence->distanceMeters,
                    'geofence_radius_meters' => $geofence->appliedRadiusMeters,
                    'geofence_passed' => false,
                    'notes' => $geofence->reason,
                    'metadata' => ['attempted_action' => 'clock_in'],
                    'created_at' => $now,
                ]);

                AttendanceGeofenceRejected::dispatch($lockedEmployee, $rejectedEvent);

                $msg = $geofence->reason === 'no_authorized_branches'
                    ? 'No tienes sucursales activas autorizadas para registrar asistencia.'
                    : sprintf(
                        'Ubicación fuera de geocerca (%s: %.0f m vs radio permitido de %d m). El intento fue registrado para revisión.',
                        $geofence->branch?->name ?? 'Sucursal',
                        $geofence->distanceMeters ?? 0,
                        $geofence->appliedRadiusMeters ?? 0,
                    );

                return new AttendanceActionResult(
                    ok: false,
                    code: $geofence->reason,
                    message: $msg,
                    event: $rejectedEvent,
                    geofence: $geofence,
                );
            }

            $branch = $geofence->branch;
            $localWorkDate = $now->copy()->setTimezone($branch->timezone)->toDateString();
            $schedule = $this->resolveSchedule($lockedEmployee, $localWorkDate);

            $lateMinutes = 0;
            if ($schedule !== null) {
                $window = $this->scheduleEvaluator->resolveShiftWindowForClockIn($schedule, $now);
                if ($window !== null) {
                    $localWorkDate = $window['work_date'];
                }
                $lateMinutes = $this->scheduleEvaluator->calculateLateMinutes($schedule, $now, $localWorkDate);
            }

            $record = AttendanceRecord::withoutGlobalScopes()->create([
                'tenant_id' => $lockedEmployee->tenant_id,
                'employee_id' => $lockedEmployee->id,
                'branch_id' => $branch->id,
                'schedule_id' => $schedule?->id,
                'work_date' => $localWorkDate,
                'status' => AttendanceStatus::Working,
                'clock_in_at' => $now,
                'total_break_minutes' => 0,
                'late_minutes' => $lateMinutes,
                'early_departure_minutes' => 0,
            ]);

            if ($lateMinutes > 0) {
                $this->incidentRecorder->recordDailyIncident(
                    tenantId: $lockedEmployee->tenant_id,
                    employeeId: $lockedEmployee->id,
                    incidentDate: $localWorkDate,
                    type: IncidentType::Late,
                    attendanceRecordId: $record->id,
                    branchId: $branch->id,
                    minutesAffected: $lateMinutes,
                    metadata: ['schedule_id' => $schedule?->id],
                );
            }

            $event = AttendanceEvent::withoutGlobalScopes()->create([
                'tenant_id' => $lockedEmployee->tenant_id,
                'attendance_record_id' => $record->id,
                'employee_id' => $lockedEmployee->id,
                'branch_id' => $branch->id,
                'event_type' => AttendanceEventType::ClockIn,
                'source' => $source,
                'whatsapp_channel_id' => $whatsappChannelId,
                'wamid' => $wamid,
                'received_at' => $received,
                'event_at' => $now,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'distance_meters' => $geofence->distanceMeters,
                'geofence_radius_meters' => $geofence->appliedRadiusMeters,
                'geofence_passed' => true,
                'created_at' => $now,
            ]);

            $localTime = $now->copy()->setTimezone($branch->timezone)->format('H:i');

            return new AttendanceActionResult(
                ok: true,
                code: 'clocked_in',
                message: sprintf(
                    'Entrada registrada en %s a las %s (%s). Distancia: %.0f m.',
                    $branch->name,
                    $localTime,
                    $branch->timezone,
                    $geofence->distanceMeters ?? 0,
                ),
                record: $record->load('branch', 'schedule'),
                event: $event,
                geofence: $geofence,
            );
        });
    }

    public function startBreak(
        Employee $employee,
        AttendanceSource $source,
        ?int $whatsappChannelId = null,
        ?string $wamid = null,
        ?CarbonInterface $receivedAt = null,
        ?CarbonInterface $eventAt = null,
    ): AttendanceActionResult {
        $now = ($eventAt ?? now())->utc();
        $received = ($receivedAt ?? $now)->utc();

        return DB::transaction(function () use ($employee, $source, $whatsappChannelId, $wamid, $received, $now): AttendanceActionResult {
            $openRecord = $this->findOpenRecord($employee, lock: true);
            if ($openRecord === null) {
                return new AttendanceActionResult(
                    ok: false,
                    code: 'no_open_shift',
                    message: 'No tienes una jornada abierta para iniciar descanso.',
                );
            }

            if ($openRecord->status === AttendanceStatus::OnBreak) {
                return new AttendanceActionResult(
                    ok: false,
                    code: 'already_on_break',
                    message: 'Ya te encuentras en un descanso activo.',
                    record: $openRecord,
                );
            }

            if ($openRecord->schedule_id !== null) {
                $openRecord->loadMissing('schedule');
                $maxBreaks = $openRecord->schedule?->max_breaks_per_shift;
                if ($maxBreaks !== null && $openRecord->breaks()->count() >= $maxBreaks) {
                    return new AttendanceActionResult(
                        ok: false,
                        code: 'max_breaks_reached',
                        message: "Alcanzaste el límite de {$maxBreaks} descansos permitidos en este turno.",
                        record: $openRecord,
                    );
                }
            }

            AttendanceBreak::withoutGlobalScopes()->create([
                'tenant_id' => $openRecord->tenant_id,
                'attendance_record_id' => $openRecord->id,
                'started_at' => $now,
            ]);

            $openRecord->update(['status' => AttendanceStatus::OnBreak]);

            $event = AttendanceEvent::withoutGlobalScopes()->create([
                'tenant_id' => $openRecord->tenant_id,
                'attendance_record_id' => $openRecord->id,
                'employee_id' => $openRecord->employee_id,
                'branch_id' => $openRecord->branch_id,
                'event_type' => AttendanceEventType::BreakStart,
                'source' => $source,
                'whatsapp_channel_id' => $whatsappChannelId,
                'wamid' => $wamid,
                'received_at' => $received,
                'event_at' => $now,
                'created_at' => $now,
            ]);

            $tz = $openRecord->branch?->timezone ?? $openRecord->tenant?->timezone ?? 'UTC';
            $localTime = $now->copy()->setTimezone($tz)->format('H:i');

            return new AttendanceActionResult(
                ok: true,
                code: 'break_started',
                message: "Descanso iniciado a las {$localTime}.",
                record: $openRecord->refresh(),
                event: $event,
            );
        });
    }

    public function endBreak(
        Employee $employee,
        AttendanceSource $source,
        ?int $whatsappChannelId = null,
        ?string $wamid = null,
        ?CarbonInterface $receivedAt = null,
        ?CarbonInterface $eventAt = null,
    ): AttendanceActionResult {
        $now = ($eventAt ?? now())->utc();
        $received = ($receivedAt ?? $now)->utc();

        return DB::transaction(function () use ($employee, $source, $whatsappChannelId, $wamid, $received, $now): AttendanceActionResult {
            $openRecord = $this->findOpenRecord($employee, lock: true);
            if ($openRecord === null || $openRecord->status !== AttendanceStatus::OnBreak) {
                return new AttendanceActionResult(
                    ok: false,
                    code: 'not_on_break',
                    message: 'No tienes un descanso activo para finalizar.',
                    record: $openRecord,
                );
            }

            /** @var AttendanceBreak|null $openBreak */
            $openBreak = AttendanceBreak::withoutGlobalScopes()
                ->where('tenant_id', $openRecord->tenant_id)
                ->where('attendance_record_id', $openRecord->id)
                ->whereNull('ended_at')
                ->lockForUpdate()
                ->first();

            if ($openBreak === null) {
                return new AttendanceActionResult(
                    ok: false,
                    code: 'not_on_break',
                    message: 'No se encontró un descanso abierto.',
                    record: $openRecord,
                );
            }

            $durationMinutes = max(0, (int) $openBreak->started_at->diffInMinutes($now));
            $openBreak->update([
                'ended_at' => $now,
                'duration_minutes' => $durationMinutes,
            ]);

            $openRecord->update([
                'status' => AttendanceStatus::Working,
                'total_break_minutes' => (int) $openRecord->total_break_minutes + $durationMinutes,
            ]);

            $event = AttendanceEvent::withoutGlobalScopes()->create([
                'tenant_id' => $openRecord->tenant_id,
                'attendance_record_id' => $openRecord->id,
                'employee_id' => $openRecord->employee_id,
                'branch_id' => $openRecord->branch_id,
                'event_type' => AttendanceEventType::BreakEnd,
                'source' => $source,
                'whatsapp_channel_id' => $whatsappChannelId,
                'wamid' => $wamid,
                'received_at' => $received,
                'event_at' => $now,
                'created_at' => $now,
            ]);

            return new AttendanceActionResult(
                ok: true,
                code: 'break_ended',
                message: "Descanso finalizado ({$durationMinutes} min). Jornada reanudada.",
                record: $openRecord->refresh(),
                event: $event,
            );
        });
    }

    public function clockOut(
        Employee $employee,
        float $latitude,
        float $longitude,
        AttendanceSource $source,
        ?int $whatsappChannelId = null,
        ?string $wamid = null,
        ?CarbonInterface $receivedAt = null,
        ?CarbonInterface $eventAt = null,
    ): AttendanceActionResult {
        $now = ($eventAt ?? now())->utc();
        $received = ($receivedAt ?? $now)->utc();

        return DB::transaction(function () use ($employee, $latitude, $longitude, $source, $whatsappChannelId, $wamid, $received, $now): AttendanceActionResult {
            $openRecord = $this->findOpenRecord($employee, lock: true);

            if ($openRecord === null) {
                return new AttendanceActionResult(
                    ok: false,
                    code: 'no_open_shift',
                    message: 'No tienes una jornada abierta para registrar salida.',
                );
            }

            if ($openRecord->status === AttendanceStatus::OnBreak) {
                return new AttendanceActionResult(
                    ok: false,
                    code: 'break_still_open',
                    message: 'Debes terminar tu descanso antes de registrar la salida.',
                    record: $openRecord,
                );
            }

            $geofence = $this->geofenceEvaluator->evaluate($employee, $latitude, $longitude, $now);

            if (! $geofence->passed || $geofence->branch === null) {
                $rejectedEvent = AttendanceEvent::withoutGlobalScopes()->create([
                    'tenant_id' => $openRecord->tenant_id,
                    'attendance_record_id' => $openRecord->id,
                    'employee_id' => $openRecord->employee_id,
                    'branch_id' => $geofence->branch?->id ?? $openRecord->branch_id,
                    'event_type' => AttendanceEventType::RejectedGeofence,
                    'source' => $source,
                    'whatsapp_channel_id' => $whatsappChannelId,
                    'wamid' => $wamid,
                    'received_at' => $received,
                    'event_at' => $now,
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                    'distance_meters' => $geofence->distanceMeters,
                    'geofence_radius_meters' => $geofence->appliedRadiusMeters,
                    'geofence_passed' => false,
                    'notes' => $geofence->reason,
                    'metadata' => ['attempted_action' => 'clock_out'],
                    'created_at' => $now,
                ]);

                AttendanceGeofenceRejected::dispatch($employee, $rejectedEvent);

                return new AttendanceActionResult(
                    ok: false,
                    code: $geofence->reason,
                    message: sprintf(
                        'Ubicación fuera de geocerca para salida (%s: %.0f m vs radio permitido de %d m).',
                        $geofence->branch?->name ?? 'Sucursal',
                        $geofence->distanceMeters ?? 0,
                        $geofence->appliedRadiusMeters ?? 0,
                    ),
                    record: $openRecord,
                    event: $rejectedEvent,
                    geofence: $geofence,
                );
            }

            $grossMinutes = max(0, (int) $openRecord->clock_in_at->diffInMinutes($now));
            $workedMinutes = max(0, $grossMinutes - (int) $openRecord->total_break_minutes);

            $earlyDepartureMinutes = 0;
            $schedule = $openRecord->schedule;
            $workDate = $openRecord->work_date->toDateString();

            if ($schedule !== null) {
                $earlyDepartureMinutes = $this->scheduleEvaluator->calculateEarlyDepartureMinutes($schedule, $now, $workDate);
            }

            $openRecord->update([
                'status' => AttendanceStatus::Closed,
                'clock_out_at' => $now,
                'total_worked_minutes' => $workedMinutes,
                'early_departure_minutes' => $earlyDepartureMinutes,
            ]);

            if ($earlyDepartureMinutes > 0) {
                $this->incidentRecorder->recordDailyIncident(
                    tenantId: $openRecord->tenant_id,
                    employeeId: $openRecord->employee_id,
                    incidentDate: $workDate,
                    type: IncidentType::EarlyDeparture,
                    attendanceRecordId: $openRecord->id,
                    branchId: $geofence->branch->id,
                    minutesAffected: $earlyDepartureMinutes,
                );
            }

            if ($schedule !== null && (int) $openRecord->total_break_minutes > (int) $schedule->break_duration_minutes) {
                $excessMinutes = (int) $openRecord->total_break_minutes - (int) $schedule->break_duration_minutes;
                $this->incidentRecorder->recordDailyIncident(
                    tenantId: $openRecord->tenant_id,
                    employeeId: $openRecord->employee_id,
                    incidentDate: $workDate,
                    type: IncidentType::ExcessBreak,
                    attendanceRecordId: $openRecord->id,
                    branchId: $geofence->branch->id,
                    minutesAffected: $excessMinutes,
                );
            }

            $event = AttendanceEvent::withoutGlobalScopes()->create([
                'tenant_id' => $openRecord->tenant_id,
                'attendance_record_id' => $openRecord->id,
                'employee_id' => $openRecord->employee_id,
                'branch_id' => $geofence->branch->id,
                'event_type' => AttendanceEventType::ClockOut,
                'source' => $source,
                'whatsapp_channel_id' => $whatsappChannelId,
                'wamid' => $wamid,
                'received_at' => $received,
                'event_at' => $now,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'distance_meters' => $geofence->distanceMeters,
                'geofence_radius_meters' => $geofence->appliedRadiusMeters,
                'geofence_passed' => true,
                'created_at' => $now,
            ]);

            $localTime = $now->copy()->setTimezone($geofence->branch->timezone)->format('H:i');

            return new AttendanceActionResult(
                ok: true,
                code: 'clocked_out',
                message: sprintf(
                    'Salida registrada en %s a las %s. Tiempo trabajado: %d min.',
                    $geofence->branch->name,
                    $localTime,
                    $workedMinutes,
                ),
                record: $openRecord->refresh(),
                event: $event,
                geofence: $geofence,
            );
        });
    }

    private function findOpenRecord(Employee $employee, bool $lock): ?AttendanceRecord
    {
        $query = AttendanceRecord::withoutGlobalScopes()
            ->where('tenant_id', $employee->tenant_id)
            ->where('employee_id', $employee->id)
            ->whereIn('status', [AttendanceStatus::Working->value, AttendanceStatus::OnBreak->value]);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function resolveSchedule(Employee $employee, string $localWorkDate): ?Schedule
    {
        $assignment = ShiftAssignment::withoutGlobalScopes()
            ->with('schedule')
            ->where('tenant_id', $employee->tenant_id)
            ->where('employee_id', $employee->id)
            ->where('effective_from', '<=', $localWorkDate)
            ->where(function ($q) use ($localWorkDate): void {
                $q->whereNull('effective_until')
                    ->orWhere('effective_until', '>=', $localWorkDate);
            })
            ->orderByDesc('effective_from')
            ->first();

        $schedule = $assignment?->schedule;

        return ($schedule !== null && $schedule->status === 'active') ? $schedule : null;
    }
}
