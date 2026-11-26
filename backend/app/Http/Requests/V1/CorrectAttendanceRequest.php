<?php

declare(strict_types=1);

namespace App\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CorrectAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'branch_id' => ['sometimes', 'integer'],
            'clock_in_at' => ['sometimes', 'date'],
            'clock_out_at' => ['sometimes', 'nullable', 'date'],
            'status' => ['sometimes', Rule::in(['working', 'on_break', 'closed'])],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }
}
