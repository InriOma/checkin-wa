<?php

declare(strict_types=1);

namespace App\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWhatsAppChannelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'display_phone_number' => ['sometimes', 'string', 'max:32'],
            'business_account_id' => ['sometimes', 'string', 'max:64'],
            'access_token' => ['sometimes', 'string', 'min:10'],
            'status' => ['sometimes', Rule::in(['active', 'inactive', 'pending_verification'])],
        ];
    }
}
