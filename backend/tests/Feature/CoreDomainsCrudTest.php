<?php

declare(strict_types=1);

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Branches\Models\Branch;
use App\Domains\Identity\Enums\TenantRole;
use App\Domains\Identity\Models\TenantMembership;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;

it('creates branches, employees, schedules, assignments, and audit logs within active tenant', function (): void {
    $tenant = Tenant::factory()->create(['code' => 'CORP01']);
    $owner = User::factory()->create();

    TenantMembership::query()->create([
        'tenant_id' => $tenant->id,
        'user_id' => $owner->id,
        'role' => TenantRole::CompanyOwner->value,
        'status' => 'active',
    ]);

    $headers = ['X-Tenant-Id' => (string) $tenant->id];

    $branchResp = $this->actingAs($owner, 'sanctum')
        ->withHeaders($headers)
        ->postJson('/api/v1/branches', [
            'code' => 'HQ01',
            'name' => 'Matriz Centro',
            'address' => 'Calle 1 #100',
            'latitude' => 19.4326077,
            'longitude' => -99.1332080,
            'geofence_radius_meters' => 150,
            'timezone' => 'America/Mexico_City',
        ]);

    $branchResp->assertCreated()->assertJsonPath('data.code', 'HQ01');
    $branchId = $branchResp->json('data.id');

    $employeeResp = $this->actingAs($owner, 'sanctum')
        ->withHeaders($headers)
        ->postJson('/api/v1/employees', [
            'employee_code' => 'E100',
            'first_name' => 'María',
            'last_name' => 'López',
            'email' => 'maria@corp01.local',
            'default_branch_id' => $branchId,
        ]);

    $employeeResp->assertCreated()
        ->assertJsonPath('data.employee_code', 'E100')
        ->assertJsonPath('data.default_branch.id', $branchId);
    $employeeId = $employeeResp->json('data.id');

    $scheduleResp = $this->actingAs($owner, 'sanctum')
        ->withHeaders($headers)
        ->postJson('/api/v1/schedules', [
            'name' => 'Nocturno',
            'timezone' => 'America/Mexico_City',
            'work_days' => [1, 2, 3, 4, 5],
            'start_time' => '22:00',
            'end_time' => '06:00',
            'late_tolerance_minutes' => 15,
            'absence_threshold_minutes' => 90,
            'break_duration_minutes' => 30,
            'max_breaks_per_shift' => 1,
        ]);

    $scheduleResp->assertCreated()
        ->assertJsonPath('data.is_overnight', true);
    $scheduleId = $scheduleResp->json('data.id');

    $assignResp = $this->actingAs($owner, 'sanctum')
        ->withHeaders($headers)
        ->postJson("/api/v1/schedules/{$scheduleId}/assign", [
            'employee_id' => $employeeId,
            'effective_from' => '2026-11-01',
        ]);

    $assignResp->assertCreated()
        ->assertJsonPath('data.employee.id', $employeeId)
        ->assertJsonPath('data.schedule.id', $scheduleId);

    $branch2 = Branch::factory()->create([
        'tenant_id' => $tenant->id,
        'code' => 'HQ02',
    ]);

    $this->actingAs($owner, 'sanctum')
        ->withHeaders($headers)
        ->putJson("/api/v1/employees/{$employeeId}/branches", [
            'branch_ids' => [$branchId, $branch2->id],
            'effective_from' => '2026-11-01',
        ])
        ->assertOk()
        ->assertJsonCount(2, 'data.branch_assignments');

    expect(AuditLog::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())->toBe(5);
});

it('validates geographic coordinates and schedule day bounds', function (): void {
    $tenant = Tenant::factory()->create(['code' => 'VAL01']);
    $hr = User::factory()->create();

    TenantMembership::query()->create([
        'tenant_id' => $tenant->id,
        'user_id' => $hr->id,
        'role' => TenantRole::HrAdmin->value,
        'status' => 'active',
    ]);

    $headers = ['X-Tenant-Id' => (string) $tenant->id];

    $this->actingAs($hr, 'sanctum')
        ->withHeaders($headers)
        ->postJson('/api/v1/branches', [
            'code' => 'BAD1',
            'name' => 'Bad Coords',
            'latitude' => 95.0,
            'longitude' => -200.0,
            'geofence_radius_meters' => 5,
            'timezone' => 'Invalid/Timezone',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['latitude', 'longitude', 'geofence_radius_meters', 'timezone']);

    $this->actingAs($hr, 'sanctum')
        ->withHeaders($headers)
        ->postJson('/api/v1/schedules', [
            'name' => 'Bad Days',
            'work_days' => [0, 8],
            'start_time' => '99:00',
            'end_time' => '18:00',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['work_days.0', 'work_days.1', 'start_time']);
});
