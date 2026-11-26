<?php

declare(strict_types=1);

namespace App\Domains\WhatsApp\Models;

use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class WhatsAppChannel extends Model
{
    use SoftDeletes;

    protected $table = 'whatsapp_channels';

    protected $fillable = [
        'tenant_id',
        'channel_type',
        'phone_number_id',
        'business_account_id',
        'display_phone_number',
        'access_token_encrypted',
        'status',
    ];

    protected $hidden = [
        'access_token_encrypted',
    ];

    protected function casts(): array
    {
        return [
            'access_token_encrypted' => 'encrypted',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(WhatsAppMessage::class, 'whatsapp_channel_id');
    }

    public function isCentral(): bool
    {
        return $this->channel_type === 'central' && $this->tenant_id === null;
    }

    public function isDedicated(): bool
    {
        return $this->channel_type === 'dedicated' && $this->tenant_id !== null;
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
