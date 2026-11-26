<?php

declare(strict_types=1);

namespace App\Domains\Branches\Models;

use App\Domains\Employees\Models\EmployeeBranchAssignment;
use App\Domains\Tenancy\Traits\BelongsToTenant;
use Database\Factories\BranchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Branch extends Model
{
    /** @use HasFactory<BranchFactory> */
    use BelongsToTenant, HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'code',
        'name',
        'address',
        'latitude',
        'longitude',
        'geofence_radius_meters',
        'timezone',
        'status',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $branch): void {
            $branch->code = strtoupper(trim($branch->code));
        });
    }

    protected static function newFactory(): BranchFactory
    {
        return BranchFactory::new();
    }

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'geofence_radius_meters' => 'integer',
        ];
    }

    public function branchAssignments(): HasMany
    {
        return $this->hasMany(EmployeeBranchAssignment::class, 'branch_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
