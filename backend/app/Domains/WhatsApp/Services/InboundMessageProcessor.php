<?php

declare(strict_types=1);

namespace App\Domains\WhatsApp\Services;

use App\Domains\Employees\Models\Employee;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Services\TenantContext;
use App\Domains\WhatsApp\Models\EmployeeWhatsAppContact;
use App\Domains\WhatsApp\Models\PendingAction;
use App\Domains\WhatsApp\Models\WhatsAppChannel;
use App\Domains\WhatsApp\Models\WhatsAppContact;
use App\Domains\WhatsApp\Models\WhatsAppMessage;
use Illuminate\Support\Collection;

final class InboundMessageProcessor
{
    public function __construct(
        private readonly ChannelRouterService $router,
        private readonly EmployeeOnboardingService $onboarding,
        private readonly WhatsAppCloudClient $client,
        private readonly WhatsAppAttendanceHandler $attendanceHandler,
        private readonly TenantContext $tenantContext,
    ) {}

    public function process(int $whatsappMessageId): void
    {
        $message = WhatsAppMessage::query()
            ->with(['channel.tenant', 'contact'])
            ->find($whatsappMessageId);

        if ($message === null || $message->processed_at !== null) {
            return;
        }

        $channel = $message->channel;
        $contact = $message->contact;

        if ($channel === null || $contact === null || ! $channel->isActive()) {
            $message->update(['processed_at' => now()->utc(), 'delivery_status' => 'ignored']);

            return;
        }

        [$text, $interactiveId] = $this->extractCommandInput($message->payload_redacted);

        $routing = $this->router->resolve($channel, $contact, $text, $interactiveId);

        match ($routing['status']) {
            'inactive_tenant' => $this->client->sendText(
                $channel,
                $contact->phone_e164,
                'Esta empresa no se encuentra activa en este momento.',
            ),
            'unlinked_central', 'unlinked_dedicated' => $this->onboarding->handle(
                $channel,
                $contact,
                $message,
                $text ?? '',
            ),
            'ambiguous' => $this->promptTenantSelection($channel, $contact, $routing['memberships']),
            'resolved' => $this->handleResolvedTenant(
                $channel,
                $contact,
                $message,
                $routing['tenant'],
                $routing['employee'],
                $text,
                $interactiveId,
            ),
        };

        $message->update([
            'processed_at' => now()->utc(),
            'delivery_status' => 'processed',
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{0: ?string, 1: ?string}
     */
    private function extractCommandInput(array $payload): array
    {
        $type = $payload['type'] ?? null;

        if ($type === 'text') {
            return [isset($payload['text']['body']) ? (string) $payload['text']['body'] : null, null];
        }

        if ($type === 'interactive') {
            $buttonReply = $payload['interactive']['button_reply'] ?? null;
            if (is_array($buttonReply)) {
                return [
                    isset($buttonReply['title']) ? (string) $buttonReply['title'] : null,
                    isset($buttonReply['id']) ? (string) $buttonReply['id'] : null,
                ];
            }

            $listReply = $payload['interactive']['list_reply'] ?? null;
            if (is_array($listReply)) {
                return [
                    isset($listReply['title']) ? (string) $listReply['title'] : null,
                    isset($listReply['id']) ? (string) $listReply['id'] : null,
                ];
            }
        }

        return [null, null];
    }

    /**
     * @param  Collection<int, EmployeeWhatsAppContact>  $memberships
     */
    private function promptTenantSelection(
        WhatsAppChannel $channel,
        WhatsAppContact $contact,
        Collection $memberships,
    ): void {
        PendingAction::query()
            ->where('whatsapp_channel_id', $channel->id)
            ->where('whatsapp_contact_id', $contact->id)
            ->where('action_type', 'awaiting_tenant_selection')
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()->utc()]);

        PendingAction::query()->create([
            'whatsapp_channel_id' => $channel->id,
            'whatsapp_contact_id' => $contact->id,
            'action_type' => 'awaiting_tenant_selection',
            'context' => [
                'tenant_ids' => $memberships->pluck('tenant_id')->values()->all(),
            ],
            'expires_at' => now()->utc()->addSeconds((int) config('whatsapp.selection_ttl_seconds', 600)),
        ]);

        if ($memberships->count() <= 3) {
            $buttons = $memberships->map(fn (EmployeeWhatsAppContact $m): array => [
                'id' => "select_tenant:{$m->tenant_id}",
                'title' => (string) $m->tenant?->name,
            ])->values()->all();

            $this->client->sendInteractiveButtons(
                $channel,
                $contact->phone_e164,
                'Estás vinculado a varias empresas. Selecciona con cuál deseas operar:',
                $buttons,
            );

            return;
        }

        $codes = $memberships->map(fn (EmployeeWhatsAppContact $m): string => "- {$m->tenant?->code}: {$m->tenant?->name}")
            ->implode("\n");

        $this->client->sendText(
            $channel,
            $contact->phone_e164,
            "Estás vinculado a varias empresas. Responde con el código de la empresa:\n{$codes}",
        );
    }

    private function handleResolvedTenant(
        WhatsAppChannel $channel,
        WhatsAppContact $contact,
        WhatsAppMessage $message,
        ?Tenant $tenant,
        ?Employee $employee,
        ?string $text,
        ?string $interactiveId,
    ): void {
        if ($tenant === null || $employee === null) {
            return;
        }

        $message->update(['tenant_id' => $tenant->id]);

        $this->tenantContext->runAsTenant($tenant, function () use ($channel, $contact, $message, $tenant, $employee, $text, $interactiveId): void {
            if ($interactiveId !== null && str_starts_with($interactiveId, 'select_tenant:')) {
                $this->client->sendInteractiveButtons(
                    $channel,
                    $contact->phone_e164,
                    "Empresa seleccionada: {$tenant->name} ({$employee->full_name}). Elige una opción:",
                    [
                        ['id' => 'att:clock_in', 'title' => 'Entrada'],
                        ['id' => 'att:break_start', 'title' => 'Descanso'],
                        ['id' => 'att:clock_out', 'title' => 'Salida'],
                    ],
                    $tenant->id,
                );

                return;
            }

            if ($text !== null && str_starts_with(strtoupper(trim($text)), 'VINCULAR ')) {
                $this->client->sendText(
                    $channel,
                    $contact->phone_e164,
                    "Tu número ya está vinculado a {$tenant->name} ({$employee->employee_code}).",
                    $tenant->id,
                );

                return;
            }

            $this->attendanceHandler->handle($channel, $contact, $message, $tenant, $employee, $text, $interactiveId);
        });
    }
}
