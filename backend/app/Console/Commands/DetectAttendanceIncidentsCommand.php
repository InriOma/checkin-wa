<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Incidents\Services\IncidentDetectionService;
use Illuminate\Console\Command;

class DetectAttendanceIncidentsCommand extends Command
{
    protected $signature = 'attendance:detect-incidents {--tenant= : Optional tenant ID}';

    protected $description = 'Detect employee absences and incomplete shifts across active tenants';

    public function handle(IncidentDetectionService $service): int
    {
        $tenantId = $this->option('tenant') !== null ? (int) $this->option('tenant') : null;
        $result = $service->detectAll(now()->utc(), $tenantId);

        $this->info(sprintf(
            'Incident scan completed: absences=%d, incomplete_shifts=%d',
            $result['absences_created'],
            $result['incomplete_created'],
        ));

        return self::SUCCESS;
    }
}
