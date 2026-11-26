<?php

declare(strict_types=1);

namespace App\Domains\Incidents\Enums;

enum IncidentStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
