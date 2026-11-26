<?php

declare(strict_types=1);

namespace App\Domains\Billing\Services;

use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Validation\ValidationException;

/**
 * MVP stub for enforcing per-tenant plan quotas without payment gateway coupling.
 */
final class PlanLimitChecker
{
    public function ensureCanCreateBranch(Tenant $tenant): void
    {
        $max = $tenant->plan_limits['max_branches'] ?? null;
        if ($max === null) {
            return;
        }

        $current = $tenant->branches()->count();
        if ($current >= (int) $max) {
            throw ValidationException::withMessages([
                'plan' => ["Branch limit ({$max}) reached for the current tenant plan."],
            ]);
        }
    }

    public function ensureCanCreateEmployee(Tenant $tenant): void
    {
        $max = $tenant->plan_limits['max_employees'] ?? null;
        if ($max === null) {
            return;
        }

        $current = $tenant->employees()->count();
        if ($current >= (int) $max) {
            throw ValidationException::withMessages([
                'plan' => ["Employee limit ({$max}) reached for the current tenant plan."],
            ]);
        }
    }
}
