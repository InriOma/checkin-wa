<?php

declare(strict_types=1);

use App\Domains\Attendance\Enums\AttendanceEventType;
use App\Domains\Attendance\Enums\AttendanceSource;
use App\Domains\Attendance\Enums\AttendanceStatus;
use App\Domains\Attendance\Events\AttendanceGeofenceRejected;
use App\Domains\Attendance\Models\AttendanceEvent;
use App\Domains\Attendance\Models\AttendanceRecord;
use App\Domains\Attendance\Services\AttendanceEngine;
use App\Domains\Branches\Models\Branch;
use App\Domains\Employees\Models\Employee;
use App\Domains\Employees\Models\EmployeeBranchAssignment;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

it('completes full state machine cycle and selects closest authorized branch', function (): void {
    $tenant = Tenant::factory()->create(['code' => 'ATT01']);

    $farBranch = Branch::withoutGlobalScopes()->create([
        'tenant_id' => $tenant->id,
        'code' => 'FAR',
        'name' => 'Sucursal Lejana',
        'latitude' => 19.43500,
        'longitude' => -99.17000,
        'geofence_radius_meters' => 1500,
        'timezone' => 'America/Mexico_City',
        'status' => 'active',
    ]);

    $nearBranch = Branch::withoutGlobalScopes()->create([
        'tenant_id' => $tenant->id,
        'code' => 'NEAR',
        'name' => 'Sucursal Cercana',
        'latitude' => 19.42847,
        'longitude' => -99.16283,
        'geofence_radius_meters' => 100,
        'timezone' => 'America/Mexico_City',
        'status' => 'active',
    ]);

    $employee = Employee::withoutGlobalScopes()->create([
        'tenant_id' => $tenant->id,
        'employee_code' => 'E100',
        'first_name' => 'Carlos',
        'last_name' => 'Vega',
        'default_branch_id' => $farBranch->id,
        'status' => 'active',
    ]);

    foreach ([$farBranch, $nearBranch] as $branch) {
        EmployeeBranchAssignment::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'employee_id' => $employee->id,
            'branch_id' => $branch->id,
            'effective_from' => '2026-01-01',
        ]);
    }

    /** @var AttendanceEngine $engine */
    $engine = app(AttendanceEngine::class);

    $t0 = now()->utc()->subHours(2);
    $clockIn = $engine->clockIn(
        $employee,
        19.42850,
        -99.16285,
        AttendanceSource::WhatsAppCentral,
        null,
        'wamid.in.1',
        $t0,
        $t0,
    );

    expect($clockIn->ok)->toBeTrue()
        ->and($clockIn->record->status)->toBe(AttendanceStatus::Working)
        ->and($clockIn->record->branch_id)->toBe($nearBranch->id)
        ->and($clockIn->event->geofence_passed)->toBeTrue()
        ->and($clockIn->event->geofence_radius_meters)->toBe(100);

    // Rejects double clock_in
    $dup = $engine->clockIn($employee, 19.42850, -99.16285, AttendanceSource::WhatsAppCentral, null, 'wamid.in.2', $t0->copy()->addMinutes(5), $t0->copy()->addMinutes(5));
    expect($dup->ok)->toBeFalse()->and($dup->code)->toBe('already_clocked_in');

    // Break start
    $t1 = $t0->copy()->addMinutes(30);
    $breakStart = $engine->startBreak($employee, AttendanceSource::WhatsAppCentral, null, 'wamid.bs.1', $t1, $t1);
    expect($breakStart->ok)->toBeTrue()
        ->and($breakStart->record->status)->toBe(AttendanceStatus::OnBreak);

    // Rejects nested break and clock_out while on_break
    expect($engine->startBreak($employee, AttendanceSource::WhatsAppCentral)->ok)->toBeFalse();
    expect($engine->clockOut($employee, 19.42850, -99.16285, AttendanceSource::WhatsAppCentral)->code)->toBe('break_still_open');

    // Break end (+15 min)
    $t2 = $t1->copy()->addMinutes(15);
    $breakEnd = $engine->endBreak($employee, AttendanceSource::WhatsAppCentral, null, 'wamid.be.1', $t2, $t2);
    expect($breakEnd->ok)->toBeTrue()
        ->and($breakEnd->record->status)->toBe(AttendanceStatus::Working)
        ->and($breakEnd->record->total_break_minutes)->toBe(15);

    // Clock out (+120 min from t0 => 105 min net worked)
    $t3 = $t0->copy()->addMinutes(120);
    $clockOut = $engine->clockOut($employee, 19.42850, -99.16285, AttendanceSource::WhatsAppCentral, null, 'wamid.out.1', $t3, $t3);
    expect($clockOut->ok)->toBeTrue()
        ->and($clockOut->record->status)->toBe(AttendanceStatus::Closed)
        ->and($clockOut->record->total_worked_minutes)->toBe(105);

    // Rejects clock_out after closed
    expect($engine->clockOut($employee, 19.42850, -99.16285, AttendanceSource::WhatsAppCentral)->code)->toBe('no_open_shift');
});

it('rejects outside geofence without opening shift and keeps events append-only', function (): void {
    Event::fake([AttendanceGeofenceRejected::class]);

    $tenant = Tenant::factory()->create(['code' => 'GEO01']);
    $branch = Branch::withoutGlobalScopes()->create([
        'tenant_id' => $tenant->id,
        'code' => 'HQ',
        'name' => 'Oficina Central',
        'latitude' => 19.42847,
        'longitude' => -99.16283,
        'geofence_radius_meters' => 100,
        'timezone' => 'America/Mexico_City',
        'status' => 'active',
    ]);

    $employee = Employee::withoutGlobalScopes()->create([
        'tenant_id' => $tenant->id,
        'employee_code' => 'E200',
        'first_name' => 'Elena',
        'last_name' => 'Cruz',
        'default_branch_id' => $branch->id,
        'status' => 'active',
    ]);

    /** @var AttendanceEngine $engine */
    $engine = app(AttendanceEngine::class);

    // ~670m away -> outside 100m radius
    $result = $engine->clockIn($employee, 19.42696, -99.16769, AttendanceSource::WhatsAppDedicated);

    expect($result->ok)->toBeFalse()
        ->and($result->code)->toBe('outside_geofence')
        ->and($result->event->event_type)->toBe(AttendanceEventType::RejectedGeofence)
        ->and($result->event->geofence_passed)->toBeFalse()
        ->and($result->event->distance_meters)->toBeGreaterThan(500.0)
        ->and(AttendanceRecord::withoutGlobalScopes()->count())->toBe(0);

    Event::assertDispatched(AttendanceGeofenceRejected::class);

    // Append-only enforcement
    $event = AttendanceEvent::withoutGlobalScopes()->firstOrFail();
    expect(fn () => $event->update(['notes' => 'tampered']))->toThrow(LogicException::class);
    expect(fn () => $event->delete())->toThrow(LogicException::class);
});

it('enforces single open shift per employee at database level', function (): void {
    $tenant = Tenant::factory()->create(['code' => 'UNQ01']);
    $employee = Employee::withoutGlobalScopes()->create([
        'tenant_id' => $tenant->id,
        'employee_code' => 'E300',
        'first_name' => 'Ivan',
        'last_name' => 'Diaz',
        'status' => 'active',
    ]);

    DB::table('attendance_records')->insert([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'work_date' => '2026-11-25',
        'status' => 'working',
        'clock_in_at' => now()->utc(),
        'created_at' => now()->utc(),
        'updated_at' => now()->utc(),
    ]);

    expect(fn () => DB::transaction(fn () => DB::table('attendance_records')->insert([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'work_date' => '2026-11-25',
        'status' => 'on_break',
        'clock_in_at' => now()->utc(),
        'created_at' => now()->utc(),
        'updated_at' => now()->utc(),
    ])))->toThrow(QueryException::class);
});
