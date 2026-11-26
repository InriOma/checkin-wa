<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Domains\Attendance\Models\AttendanceEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AttendanceEvent */
class AttendanceEventResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event_type' => $this->event_type->value,
            'source' => $this->source->value,
            'branch_id' => $this->branch_id,
            'wamid' => $this->wamid,
            'received_at' => $this->received_at?->toIso8601String(),
            'event_at' => $this->event_at?->toIso8601String(),
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'distance_meters' => $this->distance_meters,
            'geofence_radius_meters' => $this->geofence_radius_meters,
            'geofence_passed' => $this->geofence_passed,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
