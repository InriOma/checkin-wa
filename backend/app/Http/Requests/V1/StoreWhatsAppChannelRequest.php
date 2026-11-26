<?php

declare(strict_types=1);

namespace App\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWhatsAppChannelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'channel_type' => ['required', Rule::in(['central', 'dedicated'])],
            'tenant_id' => ['nullable', 'integer', 'exists:tenants,id'],
            'phone_number_id' => ['required', 'string', 'max:64', Rule::unique('whatsapp_channels', 'phone_number_id')],
            'business_account_id' => ['required', 'string', 'max:64'],
            'display_phone_number' => ['required', 'string', 'max:32'],
            'access_token' => ['required', 'string', 'min:10'],
            'status' => ['sometimes', Rule::in(['active', 'inactive', 'pending_verification'])],
        ];
    }
}
