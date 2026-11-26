<?php

declare(strict_types=1);

namespace App\Http\Requests\V1;

use App\Domains\Employees\Models\Employee;
use App\Domains\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->requireId();
        /** @var Employee $employee */
        $employee = $this->route('employee');

        return [
            'employee_code' => [
                'sometimes',
                'string',
                'max:32',
                'alpha_dash',
                Rule::unique('employees', 'employee_code')
                    ->where('tenant_id', $tenantId)
                    ->ignore($employee->id),
            ],
            'first_name' => ['sometimes', 'string', 'max:100'],
            'last_name' => ['sometimes', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:255'],
            'default_branch_id' => [
                'nullable',
                'integer',
                Rule::exists('branches', 'id')->where('tenant_id', $tenantId),
            ],
            'status' => ['sometimes', Rule::in(['active', 'inactive', 'suspended'])],
            'hired_at' => ['nullable', 'date'],
        ];
    }
}
