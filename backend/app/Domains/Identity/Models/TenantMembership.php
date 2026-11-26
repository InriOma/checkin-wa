<?php

declare(strict_types=1);

namespace App\Domains\Identity\Models;

use App\Domains\Identity\Enums\TenantRole;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantMembership extends Model
{
    protected $table = 'tenant_user';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'role',
        'status',
        'branch_scope_ids',
    ];

    protected function casts(): array
    {
        return [
            'role' => TenantRole::class,
            'branch_scope_ids' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function allowsBranch(int $branchId): bool
    {
        if ($this->role === TenantRole::CompanyOwner || $this->role === TenantRole::HrAdmin) {
            return true;
        }

        if ($this->role !== TenantRole::BranchManager) {
            return false;
        }

        $scoped = $this->branch_scope_ids;
        if (empty($scoped)) {
            return true;
        }

        return in_array($branchId, array_map('intval', $scoped), true);
    }
}
