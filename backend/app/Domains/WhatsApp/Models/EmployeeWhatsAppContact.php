<?php

declare(strict_types=1);

namespace App\Domains\WhatsApp\Models;

use App\Domains\Employees\Models\Employee;
use App\Domains\Tenancy\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeWhatsAppContact extends Model
{
    use BelongsToTenant;

    protected $table = 'employee_whatsapp_contacts';

    protected $fillable = [
        'tenant_id',
        'employee_id',
        'whatsapp_contact_id',
        'is_primary',
        'verified_at',
        'linked_via_channel_id',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'verified_at' => 'datetime',
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

    public function linkedViaChannel(): BelongsTo
    {
        return $this->belongsTo(WhatsAppChannel::class, 'linked_via_channel_id');
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }
}
