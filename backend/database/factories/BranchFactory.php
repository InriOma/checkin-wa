<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Branches\Models\Branch;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Branch>
 */
class BranchFactory extends Factory
{
    protected $model = Branch::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'code' => strtoupper(fake()->unique()->lexify('SUC???')),
            'name' => 'Sucursal '.fake()->city(),
            'address' => fake()->streetAddress(),
            'latitude' => fake()->latitude(19.0, 20.0),
            'longitude' => fake()->longitude(-99.5, -98.5),
            'geofence_radius_meters' => 100,
            'timezone' => 'America/Mexico_City',
            'status' => 'active',
        ];
    }
}
