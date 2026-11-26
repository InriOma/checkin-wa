<?php

declare(strict_types=1);

namespace App\Domains\Attendance\Models;

use App\Domains\Tenancy\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceBreak extends Model
{
    use BelongsToTenant;

    protected $table = 'breaks';

    protected $fillable = [
        'tenant_id',
        'attendance_record_id',
        'started_at',
        'ended_at',
        'duration_minutes',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'duration_minutes' => 'integer',
        ];
    }

    public function attendanceRecord(): BelongsTo
    {
        return $this->belongsTo(AttendanceRecord::class, 'attendance_record_id');
    }
}
