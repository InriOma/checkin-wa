<?php

declare(strict_types=1);

namespace App\Domains\Attendance\Models;

use App\Domains\Attendance\Enums\AttendanceEventType;
use App\Domains\Attendance\Enums\AttendanceSource;
use App\Domains\Branches\Models\Branch;
use App\Domains\Employees\Models\Employee;
use App\Domains\Tenancy\Traits\BelongsToTenant;
use App\Domains\WhatsApp\Models\WhatsAppChannel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class AttendanceEvent extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $table = 'attendance_events';

    protected $fillable = [
        'tenant_id',
        'attendance_record_id',
        'employee_id',
        'branch_id',
        'event_type',
        'source',
        'whatsapp_channel_id',
        'wamid',
        'received_at',
        'event_at',
        'latitude',
        'longitude',
        'distance_meters',
        'geofence_radius_meters',
        'geofence_passed',
        'notes',
        'metadata',
        'created_at',
    ];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('AttendanceEvent records are append-only and cannot be updated.');
        });

        static::deleting(function (): void {
            throw new LogicException('AttendanceEvent records are append-only and cannot be deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'event_type' => AttendanceEventType::class,
            'source' => AttendanceSource::class,
            'received_at' => 'datetime',
            'event_at' => 'datetime',
            'latitude' => 'float',
            'longitude' => 'float',
            'distance_meters' => 'float',
            'geofence_radius_meters' => 'integer',
            'geofence_passed' => 'boolean',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(AttendanceRecord::class, 'attendance_record_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function whatsappChannel(): BelongsTo
    {
        return $this->belongsTo(WhatsAppChannel::class, 'whatsapp_channel_id');
    }
}
