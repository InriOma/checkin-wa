<?php

declare(strict_types=1);

namespace App\Http\Requests\V1;

use App\Domains\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBranchRequest extends FormRequest
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
            'code' => [
                'required',
                'string',
                'max:32',
                'alpha_dash',
                Rule::unique('branches', 'code')->where('tenant_id', $tenantId),
            ],
            'name' => ['required', 'string', 'max:150'],
            'address' => ['nullable', 'string', 'max:255'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'geofence_radius_meters' => ['sometimes', 'integer', 'min:10', 'max:50000'],
            'timezone' => ['sometimes', 'string', 'timezone:all'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ];
    }
}
