<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Domains\Incidents\Models\Incident;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Incident */
class IncidentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'employee_id' => $this->employee_id,
            'attendance_record_id' => $this->attendance_record_id,
            'branch_id' => $this->branch_id,
            'incident_date' => $this->incident_date?->toDateString(),
            'type' => $this->type->value,
            'status' => $this->status->value,
            'minutes_affected' => $this->minutes_affected,
            'justification_note' => $this->justification_note,
            'metadata' => $this->metadata,
            'reviewed_by_user_id' => $this->reviewed_by_user_id,
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'employee' => new EmployeeResource($this->whenLoaded('employee')),
            'branch' => new BranchResource($this->whenLoaded('branch')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
