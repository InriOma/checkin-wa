<?php

declare(strict_types=1);

namespace App\Domains\WhatsApp\Services;

use App\Domains\Employees\Models\Employee;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\WhatsApp\Models\EmployeeWhatsAppContact;
use App\Domains\WhatsApp\Models\PendingAction;
use App\Domains\WhatsApp\Models\WhatsAppChannel;
use App\Domains\WhatsApp\Models\WhatsAppContact;
use Illuminate\Support\Collection;

final class ChannelRouterService
{
    /**
     * @return array{
     *     status: 'resolved'|'unlinked_dedicated'|'unlinked_central'|'ambiguous'|'inactive_tenant',
     *     tenant: ?Tenant,
     *     employee: ?Employee,
     *     memberships: Collection<int, EmployeeWhatsAppContact>
     * }
     */
    public function resolve(
        WhatsAppChannel $channel,
        WhatsAppContact $contact,
        ?string $incomingText = null,
        ?string $interactiveId = null,
    ): array {
        if ($channel->isDedicated()) {
            $tenant = $channel->tenant;
            if ($tenant === null || ! $tenant->isActive()) {
                return [
                    'status' => 'inactive_tenant',
                    'tenant' => null,
                    'employee' => null,
                    'memberships' => collect(),
                ];
            }

            $link = EmployeeWhatsAppContact::withoutGlobalScopes()
                ->with('employee')
                ->where('tenant_id', $tenant->id)
                ->where('whatsapp_contact_id', $contact->id)
                ->whereNotNull('verified_at')
                ->first();

            if ($link === null || $link->employee === null || $link->employee->status !== 'active') {
                return [
                    'status' => 'unlinked_dedicated',
                    'tenant' => $tenant,
                    'employee' => null,
                    'memberships' => collect(),
                ];
            }

            return [
                'status' => 'resolved',
                'tenant' => $tenant,
                'employee' => $link->employee,
                'memberships' => collect([$link]),
            ];
        }

        /** @var Collection<int, EmployeeWhatsAppContact> $memberships */
        $memberships = EmployeeWhatsAppContact::withoutGlobalScopes()
            ->with(['tenant', 'employee'])
            ->where('whatsapp_contact_id', $contact->id)
            ->whereNotNull('verified_at')
            ->get()
            ->filter(fn (EmployeeWhatsAppContact $link): bool => $link->tenant !== null
                && $link->tenant->isActive()
                && $link->employee !== null
                && $link->employee->status === 'active')
            ->values();

        if ($memberships->isEmpty()) {
            return [
                'status' => 'unlinked_central',
                'tenant' => null,
                'employee' => null,
                'memberships' => $memberships,
            ];
        }

        if ($memberships->count() === 1) {
            $only = $memberships->first();

            return [
                'status' => 'resolved',
                'tenant' => $only->tenant,
                'employee' => $only->employee,
                'memberships' => $memberships,
            ];
        }

        $selected = $this->resolveFromSelection($channel, $contact, $memberships, $incomingText, $interactiveId);
        if ($selected !== null) {
            return [
                'status' => 'resolved',
                'tenant' => $selected->tenant,
                'employee' => $selected->employee,
                'memberships' => $memberships,
            ];
        }

        return [
            'status' => 'ambiguous',
            'tenant' => null,
            'employee' => null,
            'memberships' => $memberships,
        ];
    }

    /**
     * @param  Collection<int, EmployeeWhatsAppContact>  $memberships
     */
    private function resolveFromSelection(
        WhatsAppChannel $channel,
        WhatsAppContact $contact,
        Collection $memberships,
        ?string $incomingText,
        ?string $interactiveId,
    ): ?EmployeeWhatsAppContact {
        if ($interactiveId !== null && str_starts_with($interactiveId, 'select_tenant:')) {
            $selectedTenantId = (int) substr($interactiveId, strlen('select_tenant:'));
            $match = $memberships->firstWhere('tenant_id', $selectedTenantId);
            if ($match !== null) {
                $this->storeSelectedTenant($channel, $contact, $match);

                return $match;
            }
        }

        if ($incomingText !== null) {
            $normalized = strtoupper(trim($incomingText));
            if ($normalized === 'CAMBIAR EMPRESA') {
                PendingAction::query()
                    ->where('whatsapp_channel_id', $channel->id)
                    ->where('whatsapp_contact_id', $contact->id)
                    ->whereIn('action_type', ['selected_tenant', 'awaiting_tenant_selection'])
                    ->whereNull('consumed_at')
                    ->update(['consumed_at' => now()->utc()]);

                return null;
            }

            $awaitingSelection = PendingAction::query()
                ->where('whatsapp_channel_id', $channel->id)
                ->where('whatsapp_contact_id', $contact->id)
                ->where('action_type', 'awaiting_tenant_selection')
                ->whereNull('consumed_at')
                ->where('expires_at', '>', now()->utc())
                ->latest('id')
                ->first();

            if ($awaitingSelection !== null) {
                $match = $memberships->first(
                    fn (EmployeeWhatsAppContact $m): bool => $m->tenant?->code === $normalized
                );
                if ($match !== null) {
                    $awaitingSelection->update(['consumed_at' => now()->utc()]);
                    $this->storeSelectedTenant($channel, $contact, $match);

                    return $match;
                }
            }
        }

        $activeSelection = PendingAction::query()
            ->where('whatsapp_channel_id', $channel->id)
            ->where('whatsapp_contact_id', $contact->id)
            ->where('action_type', 'selected_tenant')
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now()->utc())
            ->latest('id')
            ->first();

        if ($activeSelection !== null && $activeSelection->tenant_id !== null) {
            return $memberships->firstWhere('tenant_id', $activeSelection->tenant_id);
        }

        return null;
    }

    private function storeSelectedTenant(
        WhatsAppChannel $channel,
        WhatsAppContact $contact,
        EmployeeWhatsAppContact $membership,
    ): void {
        PendingAction::query()
            ->where('whatsapp_channel_id', $channel->id)
            ->where('whatsapp_contact_id', $contact->id)
            ->whereIn('action_type', ['selected_tenant', 'awaiting_tenant_selection'])
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()->utc()]);

        PendingAction::query()->create([
            'tenant_id' => $membership->tenant_id,
            'employee_id' => $membership->employee_id,
            'whatsapp_channel_id' => $channel->id,
            'whatsapp_contact_id' => $contact->id,
            'action_type' => 'selected_tenant',
            'context' => ['tenant_code' => $membership->tenant?->code],
            'expires_at' => now()->utc()->addSeconds((int) config('whatsapp.selection_ttl_seconds', 600)),
        ]);
    }
}
