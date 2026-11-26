<?php

declare(strict_types=1);

namespace App\Http\Requests\V1;

use App\Domains\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreJustificationRequest extends FormRequest
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
            'employee_id' => [
                'required',
                'integer',
                Rule::exists('employees', 'id')->where('tenant_id', $tenantId),
            ],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'reason_type' => ['required', Rule::in(['medical', 'vacation', 'personal', 'permission', 'other'])],
            'notes' => ['nullable', 'string', 'max:1000'],
            'status' => ['sometimes', Rule::in(['pending', 'approved'])],
        ];
    }
}
