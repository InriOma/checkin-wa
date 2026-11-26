<?php

declare(strict_types=1);

namespace App\Domains\Geolocation\Data;

use App\Domains\Branches\Models\Branch;

final readonly class GeofenceEvaluationResult
{
    public function __construct(
        public bool $passed,
        public ?Branch $branch,
        public ?float $distanceMeters,
        public ?int $appliedRadiusMeters,
        public string $reason,
    ) {}
}
