<?php

declare(strict_types=1);

namespace App\Domains\Schedules\Models;

use App\Domains\Tenancy\Traits\BelongsToTenant;
use Database\Factories\ScheduleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Schedule extends Model
{
    /** @use HasFactory<ScheduleFactory> */
    use BelongsToTenant, HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'name',
        'timezone',
        'work_days',
        'start_time',
        'end_time',
        'late_tolerance_minutes',
        'absence_threshold_minutes',
        'break_duration_minutes',
        'max_breaks_per_shift',
        'status',
    ];

    protected static function newFactory(): ScheduleFactory
    {
        return ScheduleFactory::new();
    }

    protected function casts(): array
    {
        return [
            'work_days' => 'array',
            'late_tolerance_minutes' => 'integer',
            'absence_threshold_minutes' => 'integer',
            'break_duration_minutes' => 'integer',
            'max_breaks_per_shift' => 'integer',
        ];
    }

    public function shiftAssignments(): HasMany
    {
        return $this->hasMany(ShiftAssignment::class, 'schedule_id');
    }

    public function isOvernight(): bool
    {
        return $this->end_time <= $this->start_time;
    }
}
