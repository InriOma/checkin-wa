<?php

declare(strict_types=1);

namespace App\Domains\Geolocation\Services;

use InvalidArgumentException;

final class HaversineDistanceCalculator
{
    private const EARTH_RADIUS_METERS = 6371000.0;

    public function calculateMeters(
        float $lat1,
        float $lon1,
        float $lat2,
        float $lon2,
    ): float {
        $this->validateCoordinates($lat1, $lon1);
        $this->validateCoordinates($lat2, $lon2);

        $lat1Rad = deg2rad($lat1);
        $lon1Rad = deg2rad($lon1);
        $lat2Rad = deg2rad($lat2);
        $lon2Rad = deg2rad($lon2);

        $deltaLat = $lat2Rad - $lat1Rad;
        $deltaLon = $lon2Rad - $lon1Rad;

        $a = sin($deltaLat / 2) ** 2
            + cos($lat1Rad) * cos($lat2Rad) * (sin($deltaLon / 2) ** 2);

        $c = 2 * atan2(sqrt($a), sqrt(max(0.0, 1.0 - $a)));

        return round(self::EARTH_RADIUS_METERS * $c, 2);
    }

    public function validateCoordinates(float $latitude, float $longitude): void
    {
        if (! is_finite($latitude) || $latitude < -90.0 || $latitude > 90.0) {
            throw new InvalidArgumentException("Latitude out of valid range [-90, 90]: {$latitude}");
        }

        if (! is_finite($longitude) || $longitude < -180.0 || $longitude > 180.0) {
            throw new InvalidArgumentException("Longitude out of valid range [-180, 180]: {$longitude}");
        }
    }
}
