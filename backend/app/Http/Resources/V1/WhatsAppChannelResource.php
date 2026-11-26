<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Domains\WhatsApp\Models\WhatsAppChannel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin WhatsAppChannel */
class WhatsAppChannelResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'channel_type' => $this->channel_type,
            'phone_number_id' => $this->phone_number_id,
            'business_account_id' => $this->business_account_id,
            'display_phone_number' => $this->display_phone_number,
            'token_configured' => ! empty($this->access_token_encrypted),
            'status' => $this->status,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
