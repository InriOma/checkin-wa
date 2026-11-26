<?php

declare(strict_types=1);

namespace App\Domains\WhatsApp\Services;

use App\Domains\WhatsApp\Models\WhatsAppChannel;
use App\Domains\WhatsApp\Models\WhatsAppContact;
use App\Domains\WhatsApp\Models\WhatsAppMessage;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppCloudClient
{
    public function __construct(
        private readonly PayloadRedactor $redactor,
    ) {}

    public function sendText(WhatsAppChannel $channel, string $toE164, string $body, ?int $tenantId = null): WhatsAppMessage
    {
        return $this->sendPayload($channel, $toE164, [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => PhoneNumberNormalizer::toWhatsAppId($toE164),
            'type' => 'text',
            'text' => [
                'preview_url' => false,
                'body' => $body,
            ],
        ], 'text', $tenantId);
    }

    /**
     * @param  list<array{id: string, title: string}>  $buttons
     */
    public function sendInteractiveButtons(
        WhatsAppChannel $channel,
        string $toE164,
        string $body,
        array $buttons,
        ?int $tenantId = null,
    ): WhatsAppMessage {
        $formattedButtons = array_map(static fn (array $btn): array => [
            'type' => 'reply',
            'reply' => [
                'id' => $btn['id'],
                'title' => mb_substr($btn['title'], 0, 20),
            ],
        ], array_slice($buttons, 0, 3));

        return $this->sendPayload($channel, $toE164, [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => PhoneNumberNormalizer::toWhatsAppId($toE164),
            'type' => 'interactive',
            'interactive' => [
                'type' => 'button',
                'body' => ['text' => $body],
                'action' => ['buttons' => $formattedButtons],
            ],
        ], 'interactive', $tenantId);
    }

    /**
     * Sends official Meta location request prompt for voluntary one-time GPS sharing.
     */
    public function sendLocationRequest(WhatsAppChannel $channel, string $toE164, string $body, ?int $tenantId = null): WhatsAppMessage
    {
        return $this->sendPayload($channel, $toE164, [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => PhoneNumberNormalizer::toWhatsAppId($toE164),
            'type' => 'interactive',
            'interactive' => [
                'type' => 'location_request_message',
                'body' => ['text' => $body],
                'action' => ['name' => 'send_location'],
            ],
        ], 'interactive', $tenantId);
    }

    /**
     * @param  list<array<string, mixed>>  $components
     */
    public function sendTemplate(
        WhatsAppChannel $channel,
        string $toE164,
        string $templateName,
        string $languageCode = 'es_MX',
        array $components = [],
        ?int $tenantId = null,
    ): WhatsAppMessage {
        $template = [
            'name' => $templateName,
            'language' => ['code' => $languageCode],
        ];
        if ($components !== []) {
            $template['components'] = $components;
        }

        return $this->sendPayload($channel, $toE164, [
            'messaging_product' => 'whatsapp',
            'to' => PhoneNumberNormalizer::toWhatsAppId($toE164),
            'type' => 'template',
            'template' => $template,
        ], 'template', $tenantId);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function sendPayload(
        WhatsAppChannel $channel,
        string $toE164,
        array $payload,
        string $messageType,
        ?int $tenantId,
    ): WhatsAppMessage {
        $baseUrl = config('whatsapp.graph_base_url');
        $version = config('whatsapp.graph_version');
        $endpoint = "{$baseUrl}/{$version}/{$channel->phone_number_id}/messages";

        $normalizedE164 = PhoneNumberNormalizer::toE164($toE164);
        $contact = WhatsAppContact::query()->firstOrCreate(
            ['phone_e164' => $normalizedE164],
            ['wa_id' => PhoneNumberNormalizer::toWhatsAppId($normalizedE164)],
        );

        /** @var Response $response */
        $response = Http::withToken((string) $channel->access_token_encrypted)
            ->acceptJson()
            ->timeout(10)
            ->post($endpoint, $payload);

        $responseBody = $response->json() ?? [];
        $wamid = $responseBody['messages'][0]['id'] ?? null;
        $status = $response->successful() ? 'sent' : 'failed';

        if (! $response->successful()) {
            Log::warning('WhatsApp Cloud API outbound request failed', [
                'channel_id' => $channel->id,
                'phone_number_id' => $channel->phone_number_id,
                'recipient' => PhoneNumberNormalizer::mask($normalizedE164),
                'http_status' => $response->status(),
            ]);
        }

        return WhatsAppMessage::query()->create([
            'whatsapp_channel_id' => $channel->id,
            'tenant_id' => $tenantId ?? $channel->tenant_id,
            'whatsapp_contact_id' => $contact->id,
            'direction' => 'outbound',
            'wamid' => is_string($wamid) ? $wamid : null,
            'message_type' => $messageType,
            'delivery_status' => $status,
            'payload_redacted' => $this->redactor->redact($payload),
            'received_at' => now()->utc(),
            'processed_at' => now()->utc(),
            'error_message' => $response->successful() ? null : mb_substr($response->body(), 0, 500),
        ]);
    }
}
