<?php

declare(strict_types=1);

namespace App\Domains\Attendance\Data;

use App\Domains\Attendance\Models\AttendanceEvent;
use App\Domains\Attendance\Models\AttendanceRecord;
use App\Domains\Geolocation\Data\GeofenceEvaluationResult;

final readonly class AttendanceActionResult
{
    public function __construct(
        public bool $ok,
        public string $code,
        public string $message,
        public ?AttendanceRecord $record = null,
        public ?AttendanceEvent $event = null,
        public ?GeofenceEvaluationResult $geofence = null,
    ) {}
}
