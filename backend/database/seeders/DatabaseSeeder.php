<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Branches\Models\Branch;
use App\Domains\Employees\Models\Employee;
use App\Domains\Employees\Models\EmployeeBranchAssignment;
use App\Domains\Identity\Enums\TenantRole;
use App\Domains\Identity\Models\TenantMembership;
use App\Domains\Schedules\Models\Schedule;
use App\Domains\Schedules\Models\ShiftAssignment;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $acme = Tenant::query()->firstOrCreate(
            ['code' => 'ACME01'],
            [
                'name' => 'Acme Retail MX',
                'timezone' => 'America/Mexico_City',
                'status' => 'active',
                'plan_limits' => ['max_employees' => 100, 'max_branches' => 10],
            ],
        );

        $owner = User::query()->firstOrCreate(
            ['email' => 'owner@acme.local'],
            [
                'name' => 'Acme Owner',
                'password' => Hash::make('CheckInLocal123!'),
                'is_platform_admin' => false,
                'email_verified_at' => now(),
            ],
        );

        TenantMembership::query()->firstOrCreate(
            ['tenant_id' => $acme->id, 'user_id' => $owner->id],
            ['role' => TenantRole::CompanyOwner->value, 'status' => 'active'],
        );

        $branch = Branch::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $acme->id, 'code' => 'CDMX01'],
            [
                'name' => 'Sucursal Reforma',
                'address' => 'Av. Paseo de la Reforma 222, CDMX',
                'latitude' => 19.4284700,
                'longitude' => -99.1628300,
                'geofence_radius_meters' => 120,
                'timezone' => 'America/Mexico_City',
                'status' => 'active',
            ],
        );

        $employee = Employee::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $acme->id, 'employee_code' => 'EMP001'],
            [
                'first_name' => 'Ana',
                'last_name' => 'García',
                'email' => 'ana.garcia@acme.local',
                'default_branch_id' => $branch->id,
                'status' => 'active',
                'hired_at' => now()->subMonths(6)->toDateString(),
            ],
        );

        EmployeeBranchAssignment::withoutGlobalScopes()->firstOrCreate(
            [
                'tenant_id' => $acme->id,
                'employee_id' => $employee->id,
                'branch_id' => $branch->id,
                'effective_from' => now()->subMonths(6)->toDateString(),
            ],
        );

        $schedule = Schedule::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $acme->id, 'name' => 'Turno Oficina 09-18'],
            [
                'timezone' => 'America/Mexico_City',
                'work_days' => [1, 2, 3, 4, 5],
                'start_time' => '09:00:00',
                'end_time' => '18:00:00',
                'late_tolerance_minutes' => 10,
                'absence_threshold_minutes' => 120,
                'break_duration_minutes' => 60,
                'max_breaks_per_shift' => 2,
                'status' => 'active',
            ],
        );

        ShiftAssignment::withoutGlobalScopes()->firstOrCreate(
            [
                'tenant_id' => $acme->id,
                'employee_id' => $employee->id,
                'schedule_id' => $schedule->id,
                'effective_from' => now()->subMonths(6)->toDateString(),
            ],
        );
    }
}
