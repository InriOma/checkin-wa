<?php

declare(strict_types=1);

use App\Domains\Branches\Models\Branch;
use App\Domains\Employees\Models\Employee;
use App\Domains\Identity\Enums\TenantRole;
use App\Domains\Identity\Models\TenantMembership;
use App\Domains\Schedules\Models\Schedule;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('strictly isolates branches, employees, and schedules between tenants', function (): void {
    $tenantA = Tenant::factory()->create(['code' => 'TENA']);
    $tenantB = Tenant::factory()->create(['code' => 'TENB']);

    $userA = User::factory()->create();
    TenantMembership::query()->create([
        'tenant_id' => $tenantA->id,
        'user_id' => $userA->id,
        'role' => TenantRole::CompanyOwner->value,
        'status' => 'active',
    ]);

    $branchA = Branch::withoutGlobalScopes()->create([
        'tenant_id' => $tenantA->id,
        'code' => 'MAIN',
        'name' => 'Tenant A Main',
        'latitude' => 19.43,
        'longitude' => -99.13,
        'geofence_radius_meters' => 100,
        'timezone' => 'America/Mexico_City',
        'status' => 'active',
    ]);

    $branchB = Branch::withoutGlobalScopes()->create([
        'tenant_id' => $tenantB->id,
        'code' => 'MAIN',
        'name' => 'Tenant B Main',
        'latitude' => 20.67,
        'longitude' => -103.35,
        'geofence_radius_meters' => 100,
        'timezone' => 'America/Mexico_City',
        'status' => 'active',
    ]);

    $employeeB = Employee::withoutGlobalScopes()->create([
        'tenant_id' => $tenantB->id,
        'employee_code' => 'EMP001',
        'first_name' => 'Carlos',
        'last_name' => 'Secret',
        'default_branch_id' => $branchB->id,
        'status' => 'active',
    ]);

    $scheduleB = Schedule::withoutGlobalScopes()->create([
        'tenant_id' => $tenantB->id,
        'name' => 'Tenant B Shift',
        'timezone' => 'America/Mexico_City',
        'work_days' => [1, 2, 3, 4, 5],
        'start_time' => '08:00:00',
        'end_time' => '17:00:00',
        'status' => 'active',
    ]);

    // Tenant A can list only its own branch
    $this->actingAs($userA, 'sanctum')
        ->withHeader('X-Tenant-Id', (string) $tenantA->id)
        ->getJson('/api/v1/branches')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $branchA->id);

    // Tenant A cannot read, update, or delete Tenant B resources
    $this->actingAs($userA, 'sanctum')
        ->withHeader('X-Tenant-Id', (string) $tenantA->id)
        ->getJson("/api/v1/branches/{$branchB->id}")
        ->assertNotFound();

    $this->actingAs($userA, 'sanctum')
        ->withHeader('X-Tenant-Id', (string) $tenantA->id)
        ->patchJson("/api/v1/branches/{$branchB->id}", ['name' => 'Hacked'])
        ->assertNotFound();

    $this->actingAs($userA, 'sanctum')
        ->withHeader('X-Tenant-Id', (string) $tenantA->id)
        ->deleteJson("/api/v1/branches/{$branchB->id}")
        ->assertNotFound();

    $this->actingAs($userA, 'sanctum')
        ->withHeader('X-Tenant-Id', (string) $tenantA->id)
        ->getJson("/api/v1/employees/{$employeeB->id}")
        ->assertNotFound();

    $this->actingAs($userA, 'sanctum')
        ->withHeader('X-Tenant-Id', (string) $tenantA->id)
        ->getJson("/api/v1/schedules/{$scheduleB->id}")
        ->assertNotFound();

    // Tenant A cannot attach Tenant B's branch to a Tenant A employee
    $this->actingAs($userA, 'sanctum')
        ->withHeader('X-Tenant-Id', (string) $tenantA->id)
        ->postJson('/api/v1/employees', [
            'employee_code' => 'EMP001',
            'first_name' => 'Lucia',
            'last_name' => 'Perez',
            'default_branch_id' => $branchB->id,
        ])
        ->assertStatus(422);
});

it('rejects cross-tenant foreign key links at database level', function (): void {
    $tenantA = Tenant::factory()->create(['code' => 'FKA']);
    $tenantB = Tenant::factory()->create(['code' => 'FKB']);

    $branchB = Branch::withoutGlobalScopes()->create([
        'tenant_id' => $tenantB->id,
        'code' => 'BRB',
        'name' => 'Branch B',
        'latitude' => 19.43,
        'longitude' => -99.13,
        'geofence_radius_meters' => 100,
        'timezone' => 'America/Mexico_City',
        'status' => 'active',
    ]);

    expect(fn () => DB::table('employees')->insert([
        'tenant_id' => $tenantA->id,
        'employee_code' => 'CROSS1',
        'first_name' => 'Cross',
        'last_name' => 'Tenant',
        'default_branch_id' => $branchB->id,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('enforces postgresql check constraints and row-level security policies', function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL-specific check constraints and RLS test.');
    }

    $tenantA = Tenant::factory()->create(['code' => 'RLSA']);
    $tenantB = Tenant::factory()->create(['code' => 'RLSB']);

    $branchA = Branch::withoutGlobalScopes()->create([
        'tenant_id' => $tenantA->id,
        'code' => 'A1',
        'name' => 'Branch A1',
        'latitude' => 19.43,
        'longitude' => -99.13,
        'geofence_radius_meters' => 100,
        'timezone' => 'America/Mexico_City',
        'status' => 'active',
    ]);

    $branchB = Branch::withoutGlobalScopes()->create([
        'tenant_id' => $tenantB->id,
        'code' => 'B1',
        'name' => 'Branch B1',
        'latitude' => 20.67,
        'longitude' => -103.35,
        'geofence_radius_meters' => 100,
        'timezone' => 'America/Mexico_City',
        'status' => 'active',
    ]);

    // Check constraint rejects invalid latitude at DB level (inside savepoint so outer RefreshDatabase tx stays healthy)
    expect(fn () => DB::transaction(fn () => DB::table('branches')->insert([
        'tenant_id' => $tenantA->id,
        'code' => 'BADLAT',
        'name' => 'Invalid Lat',
        'latitude' => 95.0,
        'longitude' => -99.13,
        'geofence_radius_meters' => 100,
        'timezone' => 'America/Mexico_City',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ])))->toThrow(QueryException::class);

    // RLS blocks reading tenant B rows under a non-owner role when app.current_tenant_id = tenantA
    DB::unprepared("DO \$\$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'checkin_rls_tester') THEN CREATE ROLE checkin_rls_tester NOLOGIN; END IF; END \$\$;");
    DB::unprepared('GRANT USAGE ON SCHEMA public TO checkin_rls_tester');
    DB::unprepared('GRANT SELECT ON branches TO checkin_rls_tester');

    $visibleIds = DB::transaction(function () use ($tenantA): array {
        DB::unprepared('SET LOCAL ROLE checkin_rls_tester');
        DB::statement("SELECT set_config('app.current_tenant_id', ?, true)", [(string) $tenantA->id]);

        return DB::table('branches')->pluck('id')->all();
    });

    expect($visibleIds)->toContain($branchA->id)
        ->not->toContain($branchB->id);
});
