<?php

declare(strict_types=1);

namespace App\Domains\Incidents\Models;

use App\Domains\Employees\Models\Employee;
use App\Domains\Incidents\Enums\IncidentStatus;
use App\Domains\Tenancy\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Justification extends Model
{
    use BelongsToTenant;

    protected $table = 'justifications';

    protected $fillable = [
        'tenant_id',
        'employee_id',
        'start_date',
        'end_date',
        'reason_type',
        'notes',
        'status',
        'reviewed_by_user_id',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'status' => IncidentStatus::class,
            'reviewed_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }
}
