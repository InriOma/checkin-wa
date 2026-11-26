<?php

declare(strict_types=1);

namespace App\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'code' => ['required', 'string', 'max:32', 'alpha_dash', Rule::unique('tenants', 'code')],
            'timezone' => ['sometimes', 'string', 'timezone:all'],
            'status' => ['sometimes', Rule::in(['active', 'suspended', 'trial'])],
            'plan_limits' => ['nullable', 'array'],
        ];
    }
}
