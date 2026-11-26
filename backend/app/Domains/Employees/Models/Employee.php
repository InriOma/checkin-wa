<?php

declare(strict_types=1);

namespace App\Domains\Employees\Models;

use App\Domains\Branches\Models\Branch;
use App\Domains\Schedules\Models\ShiftAssignment;
use App\Domains\Tenancy\Traits\BelongsToTenant;
use App\Domains\WhatsApp\Models\EmployeeWhatsAppContact;
use App\Models\User;
use Database\Factories\EmployeeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Employee extends Model
{
    /** @use HasFactory<EmployeeFactory> */
    use BelongsToTenant, HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'employee_code',
        'first_name',
        'last_name',
        'email',
        'default_branch_id',
        'status',
        'hired_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $employee): void {
            $employee->employee_code = strtoupper(trim($employee->employee_code));
        });
    }

    protected static function newFactory(): EmployeeFactory
    {
        return EmployeeFactory::new();
    }

    protected function casts(): array
    {
        return [
            'hired_at' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function defaultBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'default_branch_id');
    }

    public function branchAssignments(): HasMany
    {
        return $this->hasMany(EmployeeBranchAssignment::class, 'employee_id');
    }

    public function shiftAssignments(): HasMany
    {
        return $this->hasMany(ShiftAssignment::class, 'employee_id');
    }

    public function whatsappLinks(): HasMany
    {
        return $this->hasMany(EmployeeWhatsAppContact::class, 'employee_id');
    }

    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }
}
