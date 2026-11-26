<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Employees\Models\Employee;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Employee>
 */
class EmployeeFactory extends Factory
{
    protected $model = Employee::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'employee_code' => strtoupper(fake()->unique()->bothify('E###??')),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'default_branch_id' => null,
            'status' => 'active',
            'hired_at' => now()->subMonths(3)->toDateString(),
        ];
    }
}
