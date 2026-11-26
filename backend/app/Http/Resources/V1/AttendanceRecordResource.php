<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Domains\Attendance\Models\AttendanceRecord;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AttendanceRecord */
class AttendanceRecordResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'employee_id' => $this->employee_id,
            'branch_id' => $this->branch_id,
            'schedule_id' => $this->schedule_id,
            'work_date' => $this->work_date?->toDateString(),
            'status' => $this->status->value,
            'clock_in_at' => $this->clock_in_at?->toIso8601String(),
            'clock_out_at' => $this->clock_out_at?->toIso8601String(),
            'total_worked_minutes' => $this->total_worked_minutes,
            'total_break_minutes' => $this->total_break_minutes,
            'late_minutes' => $this->late_minutes,
            'early_departure_minutes' => $this->early_departure_minutes,
            'employee' => new EmployeeResource($this->whenLoaded('employee')),
            'branch' => new BranchResource($this->whenLoaded('branch')),
            'events' => AttendanceEventResource::collection($this->whenLoaded('events')),
            'breaks' => $this->whenLoaded('breaks', fn () => $this->breaks->map(fn ($b): array => [
                'id' => $b->id,
                'started_at' => $b->started_at?->toIso8601String(),
                'ended_at' => $b->ended_at?->toIso8601String(),
                'duration_minutes' => $b->duration_minutes,
            ])->values()),
        ];
    }
}
