<?php

declare(strict_types=1);

namespace App\Domains\Attendance\Enums;

enum AttendanceStatus: string
{
    case NoShiftOpen = 'no_shift_open';
    case Working = 'working';
    case OnBreak = 'on_break';
    case Closed = 'closed';
}
