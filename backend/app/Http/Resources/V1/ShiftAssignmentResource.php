<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Domains\Schedules\Models\ShiftAssignment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ShiftAssignment */
class ShiftAssignmentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'employee_id' => $this->employee_id,
            'schedule_id' => $this->schedule_id,
            'effective_from' => $this->effective_from?->toDateString(),
            'effective_until' => $this->effective_until?->toDateString(),
            'employee' => new EmployeeResource($this->whenLoaded('employee')),
            'schedule' => new ScheduleResource($this->whenLoaded('schedule')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
