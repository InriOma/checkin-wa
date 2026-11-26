<?php

declare(strict_types=1);

namespace App\Domains\Incidents\Models;

use App\Domains\Attendance\Models\AttendanceRecord;
use App\Domains\Branches\Models\Branch;
use App\Domains\Employees\Models\Employee;
use App\Domains\Incidents\Enums\IncidentStatus;
use App\Domains\Incidents\Enums\IncidentType;
use App\Domains\Tenancy\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Incident extends Model
{
    use BelongsToTenant;

    protected $table = 'incidents';

    protected $fillable = [
        'tenant_id',
        'employee_id',
        'attendance_record_id',
        'branch_id',
        'incident_date',
        'type',
        'status',
        'minutes_affected',
        'justification_note',
        'metadata',
        'reviewed_by_user_id',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'incident_date' => 'date',
            'type' => IncidentType::class,
            'status' => IncidentStatus::class,
            'minutes_affected' => 'integer',
            'metadata' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function attendanceRecord(): BelongsTo
    {
        return $this->belongsTo(AttendanceRecord::class, 'attendance_record_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }
}
