<?php

declare(strict_types=1);

namespace App\Domains\Incidents\Enums;

enum IncidentType: string
{
    case Late = 'late';
    case Absence = 'absence';
    case EarlyDeparture = 'early_departure';
    case IncompleteShift = 'incomplete_shift';
    case ExcessBreak = 'excess_break';
    case OutsideGeofence = 'outside_geofence';
}
