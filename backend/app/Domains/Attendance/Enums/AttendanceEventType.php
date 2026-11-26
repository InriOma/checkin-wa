<?php

declare(strict_types=1);

namespace App\Domains\Attendance\Enums;

enum AttendanceEventType: string
{
    case ClockIn = 'clock_in';
    case BreakStart = 'break_start';
    case BreakEnd = 'break_end';
    case ClockOut = 'clock_out';
    case Correction = 'correction';
    case RejectedGeofence = 'rejected_geofence';
}
