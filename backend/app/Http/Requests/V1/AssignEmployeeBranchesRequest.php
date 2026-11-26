<?php

declare(strict_types=1);

namespace App\Http\Requests\V1;

use App\Domains\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignEmployeeBranchesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->requireId();

        return [
            'branch_ids' => ['required', 'array', 'min:1'],
            'branch_ids.*' => [
                'integer',
                Rule::exists('branches', 'id')->where('tenant_id', $tenantId),
            ],
            'effective_from' => ['required', 'date'],
            'effective_until' => ['nullable', 'date', 'after_or_equal:effective_from'],
        ];
    }
}
