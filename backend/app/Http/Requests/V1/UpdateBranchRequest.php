<?php

declare(strict_types=1);

namespace App\Http\Requests\V1;

use App\Domains\Branches\Models\Branch;
use App\Domains\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->requireId();
        /** @var Branch $branch */
        $branch = $this->route('branch');

        return [
            'code' => [
                'sometimes',
                'string',
                'max:32',
                'alpha_dash',
                Rule::unique('branches', 'code')
                    ->where('tenant_id', $tenantId)
                    ->ignore($branch->id),
            ],
            'name' => ['sometimes', 'string', 'max:150'],
            'address' => ['nullable', 'string', 'max:255'],
            'latitude' => ['sometimes', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'numeric', 'between:-180,180'],
            'geofence_radius_meters' => ['sometimes', 'integer', 'min:10', 'max:50000'],
            'timezone' => ['sometimes', 'string', 'timezone:all'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ];
    }
}
