<?php

declare(strict_types=1);

namespace App\Domains\Incidents\Listeners;

use App\Domains\Attendance\Events\AttendanceGeofenceRejected;
use App\Domains\Incidents\Enums\IncidentStatus;
use App\Domains\Incidents\Enums\IncidentType;
use App\Domains\Incidents\Models\Incident;

final class CreateGeofenceIncidentListener
{
    public function handle(AttendanceGeofenceRejected $event): void
    {
        $attEvent = $event->event;
        $tz = $attEvent->branch?->timezone ?? $event->employee->tenant?->timezone ?? 'UTC';
        $incidentDate = $attEvent->event_at->copy()->setTimezone($tz)->toDateString();

        Incident::withoutGlobalScopes()->create([
            'tenant_id' => $attEvent->tenant_id,
            'employee_id' => $attEvent->employee_id,
            'attendance_record_id' => $attEvent->attendance_record_id,
            'branch_id' => $attEvent->branch_id,
            'incident_date' => $incidentDate,
            'type' => IncidentType::OutsideGeofence->value,
            'status' => IncidentStatus::Pending->value,
            'metadata' => [
                'attendance_event_id' => $attEvent->id,
                'latitude' => $attEvent->latitude,
                'longitude' => $attEvent->longitude,
                'distance_meters' => $attEvent->distance_meters,
                'geofence_radius_meters' => $attEvent->geofence_radius_meters,
                'attempted_action' => $attEvent->metadata['attempted_action'] ?? null,
            ],
        ]);
    }
}
