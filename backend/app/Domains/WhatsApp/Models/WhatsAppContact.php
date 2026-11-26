<?php

declare(strict_types=1);

namespace App\Domains\WhatsApp\Models;

use App\Domains\WhatsApp\Services\PhoneNumberNormalizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WhatsAppContact extends Model
{
    protected $table = 'whatsapp_contacts';

    protected $fillable = [
        'phone_e164',
        'wa_id',
        'profile_name',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $contact): void {
            $contact->phone_e164 = PhoneNumberNormalizer::toE164($contact->phone_e164);
        });
    }

    public function employeeLinks(): HasMany
    {
        return $this->hasMany(EmployeeWhatsAppContact::class, 'whatsapp_contact_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(WhatsAppMessage::class, 'whatsapp_contact_id');
    }
}
