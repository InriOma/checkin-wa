<?php

declare(strict_types=1);

use App\Domains\Geolocation\Services\HaversineDistanceCalculator;

it('calculates zero distance for identical coordinates', function (): void {
    $calc = new HaversineDistanceCalculator;

    expect($calc->calculateMeters(19.42847, -99.16283, 19.42847, -99.16283))->toBe(0.0);
});

it('calculates accurate haversine distance between two known points in mexico city', function (): void {
    $calc = new HaversineDistanceCalculator;

    // Reforma 222 -> Angel de la Independencia (~670-760m)
    $meters = $calc->calculateMeters(19.42847, -99.16283, 19.42696, -99.16769);

    expect($meters)->toBeGreaterThan(500.0)->toBeLessThan(650.0);
});

it('rejects out of range latitude and longitude', function (float $lat, float $lon): void {
    $calc = new HaversineDistanceCalculator;

    expect(fn () => $calc->calculateMeters($lat, $lon, 19.42, -99.16))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'lat > 90' => [90.1, -99.16],
    'lat < -90' => [-90.1, -99.16],
    'lon > 180' => [19.42, 180.1],
    'lon < -180' => [19.42, -180.1],
]);
