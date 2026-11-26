<?php

declare(strict_types=1);

namespace App\Domains\WhatsApp\Models;

use App\Domains\Employees\Models\Employee;
use App\Domains\Tenancy\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsAppLinkOtp extends Model
{
    use BelongsToTenant;

    protected $table = 'whatsapp_link_otps';

    protected $fillable = [
        'tenant_id',
        'employee_id',
        'whatsapp_contact_id',
        'whatsapp_channel_id',
        'code_hash',
        'attempts',
        'max_attempts',
        'expires_at',
        'consumed_at',
    ];

    protected $hidden = [
        'code_hash',
    ];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'max_attempts' => 'integer',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(WhatsAppContact::class, 'whatsapp_contact_id');
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(WhatsAppChannel::class, 'whatsapp_channel_id');
    }

    public function isUsable(): bool
    {
        return $this->consumed_at === null
            && $this->attempts < $this->max_attempts
            && $this->expires_at->isFuture();
    }
}
