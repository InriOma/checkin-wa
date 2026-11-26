<?php

declare(strict_types=1);

namespace App\Domains\Attendance\Models;

use App\Domains\Attendance\Enums\AttendanceStatus;
use App\Domains\Branches\Models\Branch;
use App\Domains\Employees\Models\Employee;
use App\Domains\Schedules\Models\Schedule;
use App\Domains\Tenancy\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttendanceRecord extends Model
{
    use BelongsToTenant;

    protected $table = 'attendance_records';

    protected $fillable = [
        'tenant_id',
        'employee_id',
        'branch_id',
        'schedule_id',
        'work_date',
        'status',
        'clock_in_at',
        'clock_out_at',
        'total_worked_minutes',
        'total_break_minutes',
        'late_minutes',
        'early_departure_minutes',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'status' => AttendanceStatus::class,
            'clock_in_at' => 'datetime',
            'clock_out_at' => 'datetime',
            'total_worked_minutes' => 'integer',
            'total_break_minutes' => 'integer',
            'late_minutes' => 'integer',
            'early_departure_minutes' => 'integer',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class, 'schedule_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(AttendanceEvent::class, 'attendance_record_id')->orderBy('event_at');
    }

    public function breaks(): HasMany
    {
        return $this->hasMany(AttendanceBreak::class, 'attendance_record_id')->orderBy('started_at');
    }
}
