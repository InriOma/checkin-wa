<?php

declare(strict_types=1);

namespace App\Domains\Incidents\Services;

use App\Domains\Incidents\Enums\IncidentStatus;
use App\Domains\Incidents\Enums\IncidentType;
use App\Domains\Incidents\Models\Incident;
use Illuminate\Database\QueryException;

final class IncidentRecorderService
{
    /**
     * Records an idempotent daily incident (late, absence, early_departure, incomplete_shift, excess_break).
     *
     * @param  array<string, mixed>|null  $metadata
     */
    public function recordDailyIncident(
        int $tenantId,
        int $employeeId,
        string $incidentDate,
        IncidentType $type,
        ?int $attendanceRecordId = null,
        ?int $branchId = null,
        ?int $minutesAffected = null,
        ?array $metadata = null,
    ): Incident {
        try {
            return Incident::withoutGlobalScopes()->firstOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'employee_id' => $employeeId,
                    'incident_date' => $incidentDate,
                    'type' => $type->value,
                ],
                [
                    'attendance_record_id' => $attendanceRecordId,
                    'branch_id' => $branchId,
                    'status' => IncidentStatus::Pending->value,
                    'minutes_affected' => $minutesAffected,
                    'metadata' => $metadata,
                ],
            );
        } catch (QueryException $e) {
            $sqlState = $e->errorInfo[0] ?? null;
            if (in_array($sqlState, ['23505', '23000'], true)) {
                return Incident::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->where('employee_id', $employeeId)
                    ->whereDate('incident_date', $incidentDate)
                    ->where('type', $type->value)
                    ->firstOrFail();
            }

            throw $e;
        }
    }
}
