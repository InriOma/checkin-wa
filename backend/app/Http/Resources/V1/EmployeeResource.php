<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Domains\Employees\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Employee */
class EmployeeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'user_id' => $this->user_id,
            'employee_code' => $this->employee_code,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => $this->full_name,
            'email' => $this->email,
            'default_branch_id' => $this->default_branch_id,
            'default_branch' => new BranchResource($this->whenLoaded('defaultBranch')),
            'branch_assignments' => $this->whenLoaded('branchAssignments', fn () => $this->branchAssignments->map(fn ($assignment): array => [
                'id' => $assignment->id,
                'branch_id' => $assignment->branch_id,
                'branch_code' => $assignment->branch?->code,
                'branch_name' => $assignment->branch?->name,
                'effective_from' => $assignment->effective_from?->toDateString(),
                'effective_until' => $assignment->effective_until?->toDateString(),
            ])->values()),
            'status' => $this->status,
            'hired_at' => $this->hired_at?->toDateString(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
