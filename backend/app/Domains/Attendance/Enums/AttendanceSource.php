<?php

declare(strict_types=1);

namespace App\Domains\Attendance\Enums;

enum AttendanceSource: string
{
    case WhatsAppCentral = 'whatsapp_central';
    case WhatsAppDedicated = 'whatsapp_dedicated';
    case AdminCorrection = 'admin_correction';
    case System = 'system';
}
