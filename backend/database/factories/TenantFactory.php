<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'public_id' => (string) Str::uuid(),
            'name' => fake()->company(),
            'code' => strtoupper(fake()->unique()->lexify('EMP???')),
            'timezone' => 'America/Mexico_City',
            'status' => 'active',
            'plan_limits' => [
                'max_employees' => 100,
                'max_branches' => 10,
                'max_dedicated_channels' => 2,
            ],
        ];
    }
}
