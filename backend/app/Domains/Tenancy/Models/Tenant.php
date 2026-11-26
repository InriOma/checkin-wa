<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Models;

use App\Domains\Branches\Models\Branch;
use App\Domains\Employees\Models\Employee;
use App\Domains\Identity\Models\TenantMembership;
use App\Domains\Schedules\Models\Schedule;
use App\Models\User;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'public_id',
        'name',
        'code',
        'timezone',
        'status',
        'plan_limits',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $tenant): void {
            if (empty($tenant->public_id)) {
                $tenant->public_id = (string) Str::uuid();
            }

            $tenant->code = strtoupper(trim($tenant->code));
        });
    }

    protected static function newFactory(): TenantFactory
    {
        return TenantFactory::new();
    }

    protected function casts(): array
    {
        return [
            'plan_limits' => 'array',
        ];
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(TenantMembership::class, 'tenant_id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'tenant_user')
            ->withPivot(['role', 'status', 'branch_scope_ids'])
            ->withTimestamps();
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class, 'tenant_id');
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'tenant_id');
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(Schedule::class, 'tenant_id');
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['active', 'trial'], true);
    }
}
