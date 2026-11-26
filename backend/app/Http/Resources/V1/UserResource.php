<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'is_platform_admin' => (bool) $this->is_platform_admin,
            'memberships' => $this->whenLoaded('memberships', fn () => $this->memberships->map(fn ($membership): array => [
                'tenant_id' => $membership->tenant_id,
                'tenant_public_id' => $membership->tenant?->public_id,
                'tenant_code' => $membership->tenant?->code,
                'tenant_name' => $membership->tenant?->name,
                'role' => $membership->role->value,
                'status' => $membership->status,
                'branch_scope_ids' => $membership->branch_scope_ids,
            ])->values()),
        ];
    }
}
