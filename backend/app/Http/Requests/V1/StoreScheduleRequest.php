<?php

declare(strict_types=1);

namespace App\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'timezone' => ['sometimes', 'string', 'timezone:all'],
            'work_days' => ['required', 'array', 'min:1'],
            'work_days.*' => ['integer', 'between:1,7'],
            'start_time' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/'],
            'end_time' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/'],
            'late_tolerance_minutes' => ['sometimes', 'integer', 'min:0', 'max:180'],
            'absence_threshold_minutes' => ['sometimes', 'integer', 'min:1', 'max:720'],
            'break_duration_minutes' => ['sometimes', 'integer', 'min:0', 'max:240'],
            'max_breaks_per_shift' => ['sometimes', 'integer', 'min:0', 'max:10'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ];
    }
}
