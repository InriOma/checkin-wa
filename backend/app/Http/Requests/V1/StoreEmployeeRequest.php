<?php

declare(strict_types=1);

namespace App\Http\Requests\V1;

use App\Domains\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmployeeRequest extends FormRequest
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
            'employee_code' => [
                'required',
                'string',
                'max:32',
                'alpha_dash',
                Rule::unique('employees', 'employee_code')->where('tenant_id', $tenantId),
            ],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:255'],
            'default_branch_id' => [
                'nullable',
                'integer',
                Rule::exists('branches', 'id')->where('tenant_id', $tenantId),
            ],
            'branch_ids' => ['nullable', 'array'],
            'branch_ids.*' => [
                'integer',
                Rule::exists('branches', 'id')->where('tenant_id', $tenantId),
            ],
            'status' => ['sometimes', Rule::in(['active', 'inactive', 'suspended'])],
            'hired_at' => ['nullable', 'date'],
        ];
    }
}
