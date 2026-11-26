<?php

declare(strict_types=1);

namespace App\Domains\WhatsApp\Models;

use App\Domains\Employees\Models\Employee;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PendingAction extends Model
{
    protected $table = 'pending_actions';

    protected $fillable = [
        'tenant_id',
        'employee_id',
        'whatsapp_channel_id',
        'whatsapp_contact_id',
        'action_type',
        'context',
        'expires_at',
        'consumed_at',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(WhatsAppChannel::class, 'whatsapp_channel_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(WhatsAppContact::class, 'whatsapp_contact_id');
    }

    public function isValid(): bool
    {
        return $this->consumed_at === null && $this->expires_at->isFuture();
    }
}
