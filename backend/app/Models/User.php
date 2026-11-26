<?php

declare(strict_types=1);

namespace App\Models;

use App\Domains\Identity\Enums\TenantRole;
use App\Domains\Identity\Models\TenantMembership;
use App\Domains\Tenancy\Models\Tenant;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'is_platform_admin',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_platform_admin' => 'boolean',
        ];
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(TenantMembership::class, 'user_id');
    }

    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class, 'tenant_user')
            ->withPivot(['role', 'status', 'branch_scope_ids'])
            ->withTimestamps();
    }

    public function membershipFor(Tenant|int $tenant): ?TenantMembership
    {
        $tenantId = $tenant instanceof Tenant ? $tenant->id : $tenant;

        return $this->memberships()
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->first();
    }

    /**
     * @param  TenantRole|list<TenantRole>  $roles
     */
    public function hasTenantRole(Tenant|int $tenant, TenantRole|array $roles): bool
    {
        if ($this->is_platform_admin) {
            return true;
        }

        $membership = $this->membershipFor($tenant);
        if ($membership === null) {
            return false;
        }

        $allowed = is_array($roles) ? $roles : [$roles];

        return in_array($membership->role, $allowed, true);
    }

    public function canAccessBranch(Tenant|int $tenant, int $branchId): bool
    {
        if ($this->is_platform_admin) {
            return true;
        }

        $membership = $this->membershipFor($tenant);
        if ($membership === null) {
            return false;
        }

        return $membership->allowsBranch($branchId);
    }
}
