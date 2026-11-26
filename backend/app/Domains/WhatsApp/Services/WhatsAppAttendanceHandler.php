<?php

declare(strict_types=1);

namespace App\Domains\WhatsApp\Services;

use App\Domains\Attendance\Enums\AttendanceSource;
use App\Domains\Attendance\Enums\AttendanceStatus;
use App\Domains\Attendance\Services\AttendanceEngine;
use App\Domains\Employees\Models\Employee;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\WhatsApp\Models\PendingAction;
use App\Domains\WhatsApp\Models\WhatsAppChannel;
use App\Domains\WhatsApp\Models\WhatsAppContact;
use App\Domains\WhatsApp\Models\WhatsAppMessage;
use Illuminate\Support\Facades\DB;

final class WhatsAppAttendanceHandler
{
    public function __construct(
        private readonly AttendanceEngine $engine,
        private readonly WhatsAppCloudClient $client,
    ) {}

    public function handle(
        WhatsAppChannel $channel,
        WhatsAppContact $contact,
        WhatsAppMessage $message,
        Tenant $tenant,
        Employee $employee,
        ?string $text,
        ?string $interactiveId,
    ): void {
        if ($message->message_type === 'location') {
            $this->handleLocation($channel, $contact, $message, $tenant, $employee);

            return;
        }

        $command = $this->normalizeCommand($text, $interactiveId);

        match ($command) {
            'clock_in' => $this->initiateClockIn($channel, $contact, $tenant, $employee),
            'break_start' => $this->handleStartBreak($channel, $contact, $message, $tenant, $employee),
            'break_end' => $this->handleEndBreak($channel, $contact, $message, $tenant, $employee),
            'clock_out' => $this->initiateClockOut($channel, $contact, $tenant, $employee),
            default => $this->sendContextualMenu($channel, $contact, $tenant, $employee),
        };
    }

    private function initiateClockIn(
        WhatsAppChannel $channel,
        WhatsAppContact $contact,
        Tenant $tenant,
        Employee $employee,
    ): void {
        $check = $this->engine->canStartClockIn($employee);
        if (! $check->ok) {
            $this->client->sendText($channel, $contact->phone_e164, $check->message, $tenant->id);

            return;
        }

        $ttl = (int) config('whatsapp.location_ttl_seconds', 120);
        $this->createLocationPendingAction($channel, $contact, $tenant, $employee, 'awaiting_location_clock_in', $ttl);

        $this->client->sendLocationRequest(
            $channel,
            $contact->phone_e164,
            "Por favor comparte tu ubicación actual para registrar tu ENTRADA en {$tenant->name} (vigente {$ttl}s).",
            $tenant->id,
        );
    }

    private function initiateClockOut(
        WhatsAppChannel $channel,
        WhatsAppContact $contact,
        Tenant $tenant,
        Employee $employee,
    ): void {
        $check = $this->engine->canStartClockOut($employee);
        if (! $check->ok) {
            $this->client->sendText($channel, $contact->phone_e164, $check->message, $tenant->id);

            return;
        }

        $ttl = (int) config('whatsapp.location_ttl_seconds', 120);
        $this->createLocationPendingAction($channel, $contact, $tenant, $employee, 'awaiting_location_clock_out', $ttl);

        $this->client->sendLocationRequest(
            $channel,
            $contact->phone_e164,
            "Por favor comparte tu ubicación actual para registrar tu SALIDA en {$tenant->name} (vigente {$ttl}s).",
            $tenant->id,
        );
    }

    private function handleStartBreak(
        WhatsAppChannel $channel,
        WhatsAppContact $contact,
        WhatsAppMessage $message,
        Tenant $tenant,
        Employee $employee,
    ): void {
        $result = $this->engine->startBreak(
            employee: $employee,
            source: $this->resolveSource($channel),
            whatsappChannelId: $channel->id,
            wamid: $message->wamid,
            receivedAt: $message->received_at,
            eventAt: now()->utc(),
        );

        if ($result->ok) {
            $this->client->sendInteractiveButtons(
                $channel,
                $contact->phone_e164,
                $result->message,
                [['id' => 'att:break_end', 'title' => 'Fin descanso']],
                $tenant->id,
            );

            return;
        }

        $this->client->sendText($channel, $contact->phone_e164, $result->message, $tenant->id);
    }

    private function handleEndBreak(
        WhatsAppChannel $channel,
        WhatsAppContact $contact,
        WhatsAppMessage $message,
        Tenant $tenant,
        Employee $employee,
    ): void {
        $result = $this->engine->endBreak(
            employee: $employee,
            source: $this->resolveSource($channel),
            whatsappChannelId: $channel->id,
            wamid: $message->wamid,
            receivedAt: $message->received_at,
            eventAt: now()->utc(),
        );

        if ($result->ok) {
            $this->client->sendInteractiveButtons(
                $channel,
                $contact->phone_e164,
                $result->message,
                [
                    ['id' => 'att:break_start', 'title' => 'Descanso'],
                    ['id' => 'att:clock_out', 'title' => 'Salida'],
                ],
                $tenant->id,
            );

            return;
        }

        $this->client->sendText($channel, $contact->phone_e164, $result->message, $tenant->id);
    }

    private function handleLocation(
        WhatsAppChannel $channel,
        WhatsAppContact $contact,
        WhatsAppMessage $message,
        Tenant $tenant,
        Employee $employee,
    ): void {
        $location = $message->payload_redacted['location'] ?? null;
        if (! is_array($location) || ! isset($location['latitude'], $location['longitude'])) {
            $this->client->sendText($channel, $contact->phone_e164, 'No pudimos leer las coordenadas de tu ubicación.', $tenant->id);

            return;
        }

        $latitude = (float) $location['latitude'];
        $longitude = (float) $location['longitude'];
        if ($latitude < -90.0 || $latitude > 90.0 || $longitude < -180.0 || $longitude > 180.0) {
            $this->client->sendText($channel, $contact->phone_e164, 'Las coordenadas recibidas están fuera de rango válido.', $tenant->id);

            return;
        }

        $pending = DB::transaction(function () use ($channel, $contact, $tenant, $employee): ?PendingAction {
            /** @var PendingAction|null $action */
            $action = PendingAction::query()
                ->where('whatsapp_channel_id', $channel->id)
                ->where('whatsapp_contact_id', $contact->id)
                ->where('tenant_id', $tenant->id)
                ->where('employee_id', $employee->id)
                ->whereIn('action_type', ['awaiting_location_clock_in', 'awaiting_location_clock_out'])
                ->whereNull('consumed_at')
                ->where('expires_at', '>', now()->utc())
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if ($action === null) {
                return null;
            }

            $action->update(['consumed_at' => now()->utc()]);

            return $action;
        });

        if ($pending === null) {
            $this->client->sendText(
                $channel,
                $contact->phone_e164,
                'No tienes una solicitud de ubicación vigente o expiró. Inicia nuevamente Entrada o Salida desde el menú.',
                $tenant->id,
            );

            return;
        }

        $source = $this->resolveSource($channel);
        $result = $pending->action_type === 'awaiting_location_clock_in'
            ? $this->engine->clockIn($employee, $latitude, $longitude, $source, $channel->id, $message->wamid, $message->received_at, now()->utc())
            : $this->engine->clockOut($employee, $latitude, $longitude, $source, $channel->id, $message->wamid, $message->received_at, now()->utc());

        $this->client->sendText($channel, $contact->phone_e164, $result->message, $tenant->id);
    }

    private function sendContextualMenu(
        WhatsAppChannel $channel,
        WhatsAppContact $contact,
        Tenant $tenant,
        Employee $employee,
    ): void {
        $state = $this->engine->getCurrentState($employee);

        $buttons = match ($state) {
            AttendanceStatus::NoShiftOpen, AttendanceStatus::Closed => [
                ['id' => 'att:clock_in', 'title' => 'Entrada'],
            ],
            AttendanceStatus::Working => [
                ['id' => 'att:break_start', 'title' => 'Descanso'],
                ['id' => 'att:clock_out', 'title' => 'Salida'],
            ],
            AttendanceStatus::OnBreak => [
                ['id' => 'att:break_end', 'title' => 'Fin descanso'],
            ],
        };

        $this->client->sendInteractiveButtons(
            $channel,
            $contact->phone_e164,
            "Hola {$employee->first_name} ({$tenant->name}). Selecciona tu movimiento de asistencia:",
            $buttons,
            $tenant->id,
        );
    }

    private function createLocationPendingAction(
        WhatsAppChannel $channel,
        WhatsAppContact $contact,
        Tenant $tenant,
        Employee $employee,
        string $actionType,
        int $ttlSeconds,
    ): void {
        PendingAction::query()
            ->where('whatsapp_channel_id', $channel->id)
            ->where('whatsapp_contact_id', $contact->id)
            ->whereIn('action_type', ['awaiting_location_clock_in', 'awaiting_location_clock_out'])
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()->utc()]);

        PendingAction::query()->create([
            'tenant_id' => $tenant->id,
            'employee_id' => $employee->id,
            'whatsapp_channel_id' => $channel->id,
            'whatsapp_contact_id' => $contact->id,
            'action_type' => $actionType,
            'expires_at' => now()->utc()->addSeconds($ttlSeconds),
        ]);
    }

    private function normalizeCommand(?string $text, ?string $interactiveId): ?string
    {
        if ($interactiveId !== null) {
            return match ($interactiveId) {
                'att:clock_in' => 'clock_in',
                'att:break_start' => 'break_start',
                'att:break_end' => 'break_end',
                'att:clock_out' => 'clock_out',
                default => null,
            };
        }

        if ($text === null) {
            return null;
        }

        $normalized = strtoupper(trim($text));

        return match ($normalized) {
            'ENTRADA', 'CLOCK IN', 'INICIO' => 'clock_in',
            'DESCANSO', 'BREAK' => 'break_start',
            'FIN DESCANSO', 'FIN_DESCANSO', 'REANUDAR' => 'break_end',
            'SALIDA', 'CLOCK OUT', 'FIN' => 'clock_out',
            default => null,
        };
    }

    private function resolveSource(WhatsAppChannel $channel): AttendanceSource
    {
        return $channel->isCentral()
            ? AttendanceSource::WhatsAppCentral
            : AttendanceSource::WhatsAppDedicated;
    }
}
