<?php

declare(strict_types=1);

namespace App\Domains\Attendance\Events;

use App\Domains\Attendance\Models\AttendanceEvent;
use App\Domains\Employees\Models\Employee;
use Illuminate\Foundation\Events\Dispatchable;

final class AttendanceGeofenceRejected
{
    use Dispatchable;

    public function __construct(
        public readonly Employee $employee,
        public readonly AttendanceEvent $event,
    ) {}
}
