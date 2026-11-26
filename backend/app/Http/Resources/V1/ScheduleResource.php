<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Domains\Schedules\Models\Schedule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Schedule */
class ScheduleResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'name' => $this->name,
            'timezone' => $this->timezone,
            'work_days' => $this->work_days,
            'start_time' => $this->start_time,
            'end_time' => $this->end_time,
            'is_overnight' => $this->isOvernight(),
            'late_tolerance_minutes' => (int) $this->late_tolerance_minutes,
            'absence_threshold_minutes' => (int) $this->absence_threshold_minutes,
            'break_duration_minutes' => (int) $this->break_duration_minutes,
            'max_breaks_per_shift' => (int) $this->max_breaks_per_shift,
            'status' => $this->status,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
