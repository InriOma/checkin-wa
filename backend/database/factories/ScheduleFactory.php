<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Schedules\Models\Schedule;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Schedule>
 */
class ScheduleFactory extends Factory
{
    protected $model = Schedule::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => 'Turno Matutino',
            'timezone' => 'America/Mexico_City',
            'work_days' => [1, 2, 3, 4, 5],
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
            'late_tolerance_minutes' => 10,
            'absence_threshold_minutes' => 120,
            'break_duration_minutes' => 30,
            'max_breaks_per_shift' => 2,
            'status' => 'active',
        ];
    }
}
