<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Domains\WhatsApp\Models\EmployeeWhatsAppContact;
use App\Domains\WhatsApp\Services\PhoneNumberNormalizer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EmployeeWhatsAppContact */
class EmployeeWhatsAppLinkResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $phone = $this->contact?->phone_e164;

        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'employee_id' => $this->employee_id,
            'whatsapp_contact_id' => $this->whatsapp_contact_id,
            'phone_masked' => $phone ? PhoneNumberNormalizer::mask($phone) : null,
            'is_primary' => (bool) $this->is_primary,
            'verified_at' => $this->verified_at?->toIso8601String(),
            'linked_via_channel_id' => $this->linked_via_channel_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
