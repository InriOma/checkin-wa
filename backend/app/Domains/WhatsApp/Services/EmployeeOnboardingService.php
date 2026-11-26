<?php

declare(strict_types=1);

namespace App\Domains\WhatsApp\Services;

use App\Domains\Audit\Services\AuditLogger;
use App\Domains\Employees\Models\Employee;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\WhatsApp\Models\EmployeeWhatsAppContact;
use App\Domains\WhatsApp\Models\PendingAction;
use App\Domains\WhatsApp\Models\WhatsAppChannel;
use App\Domains\WhatsApp\Models\WhatsAppContact;
use App\Domains\WhatsApp\Models\WhatsAppLinkOtp;
use App\Domains\WhatsApp\Models\WhatsAppMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

final class EmployeeOnboardingService
{
    public function __construct(
        private readonly WhatsAppCloudClient $client,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function handle(
        WhatsAppChannel $channel,
        WhatsAppContact $contact,
        WhatsAppMessage $inboundMessage,
        string $text,
    ): void {
        $trimmed = trim($text);

        if (preg_match('/^\d{6}$/', $trimmed) === 1) {
            $this->verifyOtp($channel, $contact, $inboundMessage, $trimmed);

            return;
        }

        if ($channel->isDedicated()) {
            $this->handleDedicatedLink($channel, $contact, $trimmed);

            return;
        }

        $this->handleCentralLink($channel, $contact, $trimmed);
    }

    private function handleCentralLink(WhatsAppChannel $channel, WhatsAppContact $contact, string $text): void
    {
        if (preg_match('/^(?:VINCULAR\s+)?([A-Z0-9_-]{2,32})\s+([A-Z0-9_-]{2,32})$/i', $text, $matches) !== 1) {
            $this->client->sendText(
                $channel,
                $contact->phone_e164,
                'Bienvenido a CheckIn WA. Para vincular tu cuenta envía: VINCULAR <CODIGO_EMPRESA> <CODIGO_EMPLEADO>',
            );

            return;
        }

        $tenantCode = strtoupper($matches[1]);
        $employeeCode = strtoupper($matches[2]);

        if (! $this->consumeRateLimit($channel, $contact)) {
            return;
        }

        $tenant = Tenant::query()
            ->where('code', $tenantCode)
            ->whereIn('status', ['active', 'trial'])
            ->first();

        $employee = $tenant !== null
            ? Employee::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('employee_code', $employeeCode)
                ->where('status', 'active')
                ->first()
            : null;

        if ($tenant === null || $employee === null) {
            $this->client->sendText(
                $channel,
                $contact->phone_e164,
                'No pudimos validar los códigos proporcionados. Verifica tu código de empresa y empleado con Recursos Humanos.',
            );

            return;
        }

        $this->issueAndSendOtp($channel, $contact, $tenant, $employee);
    }

    private function handleDedicatedLink(WhatsAppChannel $channel, WhatsAppContact $contact, string $text): void
    {
        $tenant = $channel->tenant;
        if ($tenant === null || ! $tenant->isActive()) {
            return;
        }

        if (preg_match('/^(?:VINCULAR\s+)?([A-Z0-9_-]{2,32})$/i', $text, $matches) !== 1) {
            $this->client->sendText(
                $channel,
                $contact->phone_e164,
                "Bienvenido a {$tenant->name}. Para vincular tu número envía tu código de empleado (ej. VINCULAR EMP001).",
                $tenant->id,
            );

            return;
        }

        if (! $this->consumeRateLimit($channel, $contact)) {
            return;
        }

        $employeeCode = strtoupper($matches[1]);
        $employee = Employee::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('employee_code', $employeeCode)
            ->where('status', 'active')
            ->first();

        if ($employee === null) {
            $this->client->sendText(
                $channel,
                $contact->phone_e164,
                'No pudimos validar tu código de empleado. Verifica con Recursos Humanos.',
                $tenant->id,
            );

            return;
        }

        $this->issueAndSendOtp($channel, $contact, $tenant, $employee);
    }

    private function issueAndSendOtp(
        WhatsAppChannel $channel,
        WhatsAppContact $contact,
        Tenant $tenant,
        Employee $employee,
    ): void {
        $otp = (string) random_int(100000, 999999);
        $ttlSeconds = (int) config('whatsapp.otp_ttl_seconds', 600);
        $maxAttempts = (int) config('whatsapp.otp_max_attempts', 5);

        DB::transaction(function () use ($channel, $contact, $tenant, $employee, $otp, $ttlSeconds, $maxAttempts): void {
            WhatsAppLinkOtp::withoutGlobalScopes()
                ->where('whatsapp_contact_id', $contact->id)
                ->where('whatsapp_channel_id', $channel->id)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now()->utc()]);

            WhatsAppLinkOtp::withoutGlobalScopes()->create([
                'tenant_id' => $tenant->id,
                'employee_id' => $employee->id,
                'whatsapp_contact_id' => $contact->id,
                'whatsapp_channel_id' => $channel->id,
                'code_hash' => $this->hashOtp($otp),
                'attempts' => 0,
                'max_attempts' => $maxAttempts,
                'expires_at' => now()->utc()->addSeconds($ttlSeconds),
            ]);

            PendingAction::query()
                ->where('whatsapp_channel_id', $channel->id)
                ->where('whatsapp_contact_id', $contact->id)
                ->where('action_type', 'awaiting_otp')
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now()->utc()]);

            PendingAction::query()->create([
                'tenant_id' => $tenant->id,
                'employee_id' => $employee->id,
                'whatsapp_channel_id' => $channel->id,
                'whatsapp_contact_id' => $contact->id,
                'action_type' => 'awaiting_otp',
                'context' => ['employee_code' => $employee->employee_code],
                'expires_at' => now()->utc()->addSeconds($ttlSeconds),
            ]);
        });

        $minutes = (int) ceil($ttlSeconds / 60);
        $this->client->sendText(
            $channel,
            $contact->phone_e164,
            "Tu código de verificación para {$tenant->name} es {$otp}. Expira en {$minutes} minutos. Responde con los 6 dígitos para confirmar.",
            $tenant->id,
        );
    }

    private function verifyOtp(
        WhatsAppChannel $channel,
        WhatsAppContact $contact,
        WhatsAppMessage $inboundMessage,
        string $otpInput,
    ): void {
        /** @var WhatsAppLinkOtp|null $otpRecord */
        $otpRecord = WhatsAppLinkOtp::withoutGlobalScopes()
            ->where('whatsapp_contact_id', $contact->id)
            ->where('whatsapp_channel_id', $channel->id)
            ->whereNull('consumed_at')
            ->latest('id')
            ->first();

        if ($otpRecord === null) {
            $this->client->sendText(
                $channel,
                $contact->phone_e164,
                'No tienes una solicitud de vinculación activa. Inicia con VINCULAR <EMPRESA> <EMPLEADO>.',
            );

            return;
        }

        if ($otpRecord->expires_at->isPast()) {
            $otpRecord->update(['consumed_at' => now()->utc()]);
            $this->client->sendText(
                $channel,
                $contact->phone_e164,
                'Tu código OTP expiró. Solicita una nueva vinculación.',
                $otpRecord->tenant_id,
            );

            return;
        }

        if ($otpRecord->attempts >= $otpRecord->max_attempts) {
            $this->client->sendText(
                $channel,
                $contact->phone_e164,
                'Se alcanzó el límite máximo de intentos para este código OTP. Solicita una nueva vinculación.',
                $otpRecord->tenant_id,
            );

            return;
        }

        if (! hash_equals($otpRecord->code_hash, $this->hashOtp($otpInput))) {
            $newAttempts = $otpRecord->attempts + 1;
            $otpRecord->update(['attempts' => $newAttempts]);

            $remaining = max(0, $otpRecord->max_attempts - $newAttempts);
            $msg = $remaining > 0
                ? "Código OTP incorrecto. Te quedan {$remaining} intentos."
                : 'Código OTP incorrecto. Se alcanzó el límite máximo de intentos; solicita una nueva vinculación.';

            $this->client->sendText($channel, $contact->phone_e164, $msg, $otpRecord->tenant_id);

            return;
        }

        $tenant = Tenant::query()->findOrFail($otpRecord->tenant_id);
        $employee = Employee::withoutGlobalScopes()->findOrFail($otpRecord->employee_id);

        DB::transaction(function () use ($otpRecord, $channel, $contact, $tenant, $employee, $inboundMessage): void {
            $otpRecord->update(['consumed_at' => now()->utc()]);

            PendingAction::query()
                ->where('whatsapp_channel_id', $channel->id)
                ->where('whatsapp_contact_id', $contact->id)
                ->where('action_type', 'awaiting_otp')
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now()->utc()]);

            $link = EmployeeWhatsAppContact::withoutGlobalScopes()->updateOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'whatsapp_contact_id' => $contact->id,
                ],
                [
                    'employee_id' => $employee->id,
                    'is_primary' => true,
                    'verified_at' => now()->utc(),
                    'linked_via_channel_id' => $channel->id,
                ],
            );

            // Redact OTP digits from stored inbound payload once verified.
            $payload = $inboundMessage->payload_redacted;
            if (isset($payload['text']['body'])) {
                $payload['text']['body'] = '[REDACTED_OTP]';
                $inboundMessage->update(['payload_redacted' => $payload, 'tenant_id' => $tenant->id]);
            }

            $this->auditLogger->log(
                'whatsapp.contact_linked',
                $link,
                null,
                null,
                [
                    'employee_id' => $employee->id,
                    'channel_id' => $channel->id,
                    'phone_masked' => PhoneNumberNormalizer::mask($contact->phone_e164),
                ],
                'employee_whatsapp',
            );
        });

        $this->client->sendInteractiveButtons(
            $channel,
            $contact->phone_e164,
            "Vinculación exitosa con {$tenant->name} ({$employee->full_name}). ¿Qué deseas registrar?",
            [
                ['id' => 'att:clock_in', 'title' => 'Entrada'],
                ['id' => 'att:break_start', 'title' => 'Descanso'],
                ['id' => 'att:clock_out', 'title' => 'Salida'],
            ],
            $tenant->id,
        );
    }

    private function consumeRateLimit(WhatsAppChannel $channel, WhatsAppContact $contact): bool
    {
        $key = "wa-otp:{$channel->id}:{$contact->id}";
        $maxPerHour = (int) config('whatsapp.otp_rate_limit_per_hour', 5);

        if (RateLimiter::tooManyAttempts($key, $maxPerHour)) {
            $this->client->sendText(
                $channel,
                $contact->phone_e164,
                'Has realizado demasiadas solicitudes de verificación. Intenta nuevamente más tarde.',
                $channel->tenant_id,
            );

            return false;
        }

        RateLimiter::hit($key, 3600);

        return true;
    }

    private function hashOtp(string $otp): string
    {
        return hash_hmac('sha256', $otp, (string) config('app.key'));
    }
}
