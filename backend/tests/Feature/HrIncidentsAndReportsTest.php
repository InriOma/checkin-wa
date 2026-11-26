<?php

declare(strict_types=1);

use App\Domains\Attendance\Enums\AttendanceEventType;
use App\Domains\Attendance\Enums\AttendanceSource;
use App\Domains\Attendance\Enums\AttendanceStatus;
use App\Domains\Attendance\Models\AttendanceRecord;
use App\Domains\Attendance\Services\AttendanceEngine;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Branches\Models\Branch;
use App\Domains\Employees\Models\Employee;
use App\Domains\Identity\Enums\TenantRole;
use App\Domains\Identity\Models\TenantMembership;
use App\Domains\Incidents\Enums\IncidentStatus;
use App\Domains\Incidents\Enums\IncidentType;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Services\IncidentDetectionService;
use App\Domains\Schedules\Models\Schedule;
use App\Domains\Schedules\Models\ShiftAssignment;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;

it('generates late, early departure, excess break, and outside geofence incidents', function (): void {
    $tenant = Tenant::factory()->create(['code' => 'HR01']);
    $branch = Branch::withoutGlobalScopes()->create([
        'tenant_id' => $tenant->id,
        'code' => 'B1',
        'name' => 'Matriz',
        'latitude' => 19.42847,
        'longitude' => -99.16283,
        'geofence_radius_meters' => 100,
        'timezone' => 'America/Mexico_City',
        'status' => 'active',
    ]);

    $schedule = Schedule::withoutGlobalScopes()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Oficina',
        'timezone' => 'America/Mexico_City',
        'work_days' => [1, 2, 3, 4, 5],
        'start_time' => '09:00:00',
        'end_time' => '18:00:00',
        'late_tolerance_minutes' => 10,
        'absence_threshold_minutes' => 120,
        'break_duration_minutes' => 30,
        'max_breaks_per_shift' => 2,
        'status' => 'active',
    ]);

    $employee = Employee::withoutGlobalScopes()->create([
        'tenant_id' => $tenant->id,
        'employee_code' => 'EMP01',
        'first_name' => 'Ana',
        'last_name' => 'Garcia',
        'default_branch_id' => $branch->id,
        'status' => 'active',
    ]);

    ShiftAssignment::withoutGlobalScopes()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'schedule_id' => $schedule->id,
        'effective_from' => '2026-01-01',
    ]);

    /** @var AttendanceEngine $engine */
    $engine = app(AttendanceEngine::class);

    // Wednesday 2026-11-25 09:25 Mexico City (25 min late)
    $clockInAt = CarbonImmutable::create(2026, 11, 25, 9, 25, 0, 'America/Mexico_City')->utc();
    $inRes = $engine->clockIn($employee, 19.42847, -99.16283, AttendanceSource::WhatsAppCentral, null, null, $clockInAt, $clockInAt);
    expect($inRes->ok)->toBeTrue()
        ->and($inRes->record->late_minutes)->toBe(25);

    // 45 min break (> 30 allowed -> 15 min excess)
    $bs = $clockInAt->addHours(3);
    $be = $bs->addMinutes(45);
    $engine->startBreak($employee, AttendanceSource::WhatsAppCentral, null, null, $bs, $bs);
    $engine->endBreak($employee, AttendanceSource::WhatsAppCentral, null, null, $be, $be);

    // Early clock out at 17:40 (20 min early)
    $clockOutAt = CarbonImmutable::create(2026, 11, 25, 17, 40, 0, 'America/Mexico_City')->utc();
    $outRes = $engine->clockOut($employee, 19.42847, -99.16283, AttendanceSource::WhatsAppCentral, null, null, $clockOutAt, $clockOutAt);
    expect($outRes->ok)->toBeTrue()
        ->and($outRes->record->early_departure_minutes)->toBe(20);

    $types = Incident::withoutGlobalScopes()->where('tenant_id', $tenant->id)->pluck('type')->map->value->all();
    expect($types)->toContain('late', 'excess_break', 'early_departure');

    // Second employee triggers outside_geofence incident via listener
    $emp2 = Employee::withoutGlobalScopes()->create([
        'tenant_id' => $tenant->id,
        'employee_code' => 'EMP02',
        'first_name' => 'Luis',
        'last_name' => 'Perez',
        'default_branch_id' => $branch->id,
        'status' => 'active',
    ]);

    $engine->clockIn($emp2, 19.42696, -99.16769, AttendanceSource::WhatsAppDedicated);
    expect(
        Incident::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('employee_id', $emp2->id)
            ->where('type', IncidentType::OutsideGeofence->value)
            ->exists()
    )->toBeTrue();
});

it('detects absences respecting approved justifications and detects incomplete shifts', function (): void {
    $tenant = Tenant::factory()->create(['code' => 'DET01']);
    $hr = User::factory()->create();
    TenantMembership::query()->create([
        'tenant_id' => $tenant->id,
        'user_id' => $hr->id,
        'role' => TenantRole::HrAdmin->value,
        'status' => 'active',
    ]);

    $branch = Branch::withoutGlobalScopes()->create([
        'tenant_id' => $tenant->id,
        'code' => 'B1',
        'name' => 'Sucursal',
        'latitude' => 19.42847,
        'longitude' => -99.16283,
        'geofence_radius_meters' => 100,
        'timezone' => 'America/Mexico_City',
        'status' => 'active',
    ]);

    $schedule = Schedule::withoutGlobalScopes()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Matutino',
        'timezone' => 'America/Mexico_City',
        'work_days' => [1, 2, 3, 4, 5],
        'start_time' => '09:00:00',
        'end_time' => '18:00:00',
        'absence_threshold_minutes' => 60,
        'status' => 'active',
    ]);

    $absentEmp = Employee::withoutGlobalScopes()->create([
        'tenant_id' => $tenant->id,
        'employee_code' => 'ABS01',
        'first_name' => 'Pedro',
        'last_name' => 'SinAsistencia',
        'default_branch_id' => $branch->id,
        'status' => 'active',
    ]);
    $justifiedEmp = Employee::withoutGlobalScopes()->create([
        'tenant_id' => $tenant->id,
        'employee_code' => 'JUS01',
        'first_name' => 'Sofia',
        'last_name' => 'ConPermiso',
        'default_branch_id' => $branch->id,
        'status' => 'active',
    ]);
    $openShiftEmp = Employee::withoutGlobalScopes()->create([
        'tenant_id' => $tenant->id,
        'employee_code' => 'INC01',
        'first_name' => 'Diego',
        'last_name' => 'SinSalida',
        'default_branch_id' => $branch->id,
        'status' => 'active',
    ]);

    foreach ([$absentEmp, $justifiedEmp, $openShiftEmp] as $emp) {
        ShiftAssignment::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'employee_id' => $emp->id,
            'schedule_id' => $schedule->id,
            'effective_from' => '2026-11-25',
        ]);
    }

    // Create approved justification for 2026-11-25 via API
    $this->actingAs($hr, 'sanctum')
        ->withHeader('X-Tenant-Id', (string) $tenant->id)
        ->postJson('/api/v1/justifications', [
            'employee_id' => $justifiedEmp->id,
            'start_date' => '2026-11-25',
            'end_date' => '2026-11-25',
            'reason_type' => 'medical',
            'notes' => 'Incapacidad IMSS',
            'status' => 'approved',
        ])
        ->assertCreated();

    // Open shift without clock out on 2026-11-25
    $clockInAt = CarbonImmutable::create(2026, 11, 25, 9, 0, 0, 'America/Mexico_City')->utc();
    app(AttendanceEngine::class)->clockIn($openShiftEmp, 19.42847, -99.16283, AttendanceSource::WhatsAppCentral, null, null, $clockInAt, $clockInAt);

    // Run detector at 2026-11-25 21:00 Mexico City (past 18:00 + 120m incomplete grace & past 10:00 absence threshold)
    $asOf = CarbonImmutable::create(2026, 11, 25, 21, 0, 0, 'America/Mexico_City')->utc();
    $stats = app(IncidentDetectionService::class)->detectAll($asOf, $tenant->id);

    expect($stats['absences_created'])->toBe(1)
        ->and($stats['incomplete_created'])->toBe(1);

    expect(
        Incident::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('employee_id', $justifiedEmp->id)
            ->where('type', IncidentType::Absence->value)
            ->exists()
    )->toBeFalse();
});

it('supports incident review, auditable attendance corrections, summaries, and csv export', function (): void {
    $tenant = Tenant::factory()->create(['code' => 'REV01']);
    $hr = User::factory()->create();
    TenantMembership::query()->create([
        'tenant_id' => $tenant->id,
        'user_id' => $hr->id,
        'role' => TenantRole::HrAdmin->value,
        'status' => 'active',
    ]);

    $branch = Branch::withoutGlobalScopes()->create([
        'tenant_id' => $tenant->id,
        'code' => 'B1',
        'name' => 'Matriz',
        'latitude' => 19.42847,
        'longitude' => -99.16283,
        'geofence_radius_meters' => 100,
        'timezone' => 'America/Mexico_City',
        'status' => 'active',
    ]);

    $employee = Employee::withoutGlobalScopes()->create([
        'tenant_id' => $tenant->id,
        'employee_code' => '=EMP01', // Also tests CSV formula injection sanitization
        'first_name' => 'Elena',
        'last_name' => 'Rios',
        'default_branch_id' => $branch->id,
        'status' => 'active',
    ]);

    $schedule = Schedule::withoutGlobalScopes()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Normal',
        'timezone' => 'America/Mexico_City',
        'work_days' => [1, 2, 3, 4, 5],
        'start_time' => '09:00:00',
        'end_time' => '18:00:00',
        'late_tolerance_minutes' => 10,
        'status' => 'active',
    ]);

    ShiftAssignment::withoutGlobalScopes()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'schedule_id' => $schedule->id,
        'effective_from' => '2026-01-01',
    ]);

    $clockInAt = CarbonImmutable::create(2026, 11, 25, 9, 30, 0, 'America/Mexico_City')->utc();
    $res = app(AttendanceEngine::class)->clockIn($employee, 19.42847, -99.16283, AttendanceSource::WhatsAppCentral, null, null, $clockInAt, $clockInAt);
    /** @var AttendanceRecord $record */
    $record = $res->record;

    $incident = Incident::withoutGlobalScopes()->where('attendance_record_id', $record->id)->firstOrFail();

    $headers = ['X-Tenant-Id' => (string) $tenant->id];

    // Approve incident
    $this->actingAs($hr, 'sanctum')
        ->withHeaders($headers)
        ->postJson("/api/v1/incidents/{$incident->id}/approve", [
            'justification_note' => 'Tráfico justificado',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', IncidentStatus::Approved->value);

    // Correct attendance record (closes shift without overwriting original clock_in event)
    $clockOutAt = CarbonImmutable::create(2026, 11, 25, 18, 0, 0, 'America/Mexico_City')->utc();
    $this->actingAs($hr, 'sanctum')
        ->withHeaders($headers)
        ->postJson("/api/v1/attendance/{$record->id}/correct", [
            'clock_out_at' => $clockOutAt->toIso8601String(),
            'status' => AttendanceStatus::Closed->value,
            'reason' => 'Empleado olvidó registrar salida por falla de batería.',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', AttendanceStatus::Closed->value)
        ->assertJsonPath('data.total_worked_minutes', 510)
        ->assertJsonCount(2, 'data.events');

    $events = $record->refresh()->events()->get();
    expect($events[0]->event_type)->toBe(AttendanceEventType::ClockIn)
        ->and($events[1]->event_type)->toBe(AttendanceEventType::Correction);

    expect(AuditLog::withoutGlobalScopes()->where('action', 'attendance.corrected')->count())->toBe(1);

    // Summary & CSV export
    $this->actingAs($hr, 'sanctum')
        ->withHeaders($headers)
        ->getJson('/api/v1/reports/attendance-summary?from_date=2026-11-25&to_date=2026-11-25')
        ->assertOk()
        ->assertJsonPath('data.attendance.total_records', 1)
        ->assertJsonPath('data.attendance.late_count', 1);

    $exportResp = $this->actingAs($hr, 'sanctum')
        ->withHeaders($headers)
        ->get('/api/v1/reports/attendance-export?from_date=2026-11-25&to_date=2026-11-25');

    $exportResp->assertOk();
    $csv = $exportResp->streamedContent();
    expect($csv)->toContain("'=EMP01");
});
