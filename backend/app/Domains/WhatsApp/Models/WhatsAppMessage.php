<?php

declare(strict_types=1);

namespace App\Domains\WhatsApp\Models;

use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsAppMessage extends Model
{
    protected $table = 'whatsapp_messages';

    protected $fillable = [
        'whatsapp_channel_id',
        'tenant_id',
        'whatsapp_contact_id',
        'direction',
        'wamid',
        'message_type',
        'delivery_status',
        'payload_redacted',
        'received_at',
        'processed_at',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'payload_redacted' => 'array',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(WhatsAppChannel::class, 'whatsapp_channel_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(WhatsAppContact::class, 'whatsapp_contact_id');
    }
}
