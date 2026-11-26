<?php

declare(strict_types=1);

namespace App\Domains\WhatsApp\Services;

use App\Domains\WhatsApp\Jobs\ProcessInboundWhatsAppMessageJob;
use App\Domains\WhatsApp\Models\WhatsAppChannel;
use App\Domains\WhatsApp\Models\WhatsAppContact;
use App\Domains\WhatsApp\Models\WhatsAppMessage;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

final class WebhookIngestionService
{
    private const STATUS_RANK = [
        'received' => 1,
        'queued' => 2,
        'sent' => 3,
        'delivered' => 4,
        'read' => 5,
        'failed' => 5,
    ];

    public function __construct(
        private readonly PayloadRedactor $redactor,
    ) {}

    /**
     * Persists inbound messages idempotently by Meta wamid and queues processing.
     *
     * @param  array<string, mixed>  $payload
     * @return array{queued_messages: int, duplicate_messages: int, status_updates: int, ignored_channels: int}
     */
    public function ingest(array $payload): array
    {
        $stats = [
            'queued_messages' => 0,
            'duplicate_messages' => 0,
            'status_updates' => 0,
            'ignored_channels' => 0,
        ];

        $entries = $payload['entry'] ?? [];
        if (! is_array($entries)) {
            return $stats;
        }

        foreach ($entries as $entry) {
            $changes = $entry['changes'] ?? [];
            if (! is_array($changes)) {
                continue;
            }

            foreach ($changes as $change) {
                $value = $change['value'] ?? [];
                if (! is_array($value)) {
                    continue;
                }

                $phoneNumberId = (string) ($value['metadata']['phone_number_id'] ?? '');
                if ($phoneNumberId === '') {
                    continue;
                }

                $channel = WhatsAppChannel::query()
                    ->where('phone_number_id', $phoneNumberId)
                    ->where('status', 'active')
                    ->first();

                if ($channel === null) {
                    $stats['ignored_channels']++;
                    Log::warning('WhatsApp webhook received for unknown or inactive phone_number_id', [
                        'phone_number_id' => $phoneNumberId,
                    ]);

                    continue;
                }

                $profilesByWaId = $this->extractProfiles($value['contacts'] ?? []);

                foreach (($value['statuses'] ?? []) as $statusEvent) {
                    if (is_array($statusEvent) && $this->processStatusUpdate($channel, $statusEvent)) {
                        $stats['status_updates']++;
                    }
                }

                foreach (($value['messages'] ?? []) as $inboundMessage) {
                    if (! is_array($inboundMessage)) {
                        continue;
                    }

                    $outcome = $this->ingestInboundMessage($channel, $inboundMessage, $profilesByWaId);
                    if ($outcome === 'queued') {
                        $stats['queued_messages']++;
                    } elseif ($outcome === 'duplicate') {
                        $stats['duplicate_messages']++;
                    }
                }
            }
        }

        return $stats;
    }

    /**
     * @param  array<int, mixed>  $contacts
     * @return array<string, string|null>
     */
    private function extractProfiles(array $contacts): array
    {
        $profiles = [];
        foreach ($contacts as $contact) {
            if (! is_array($contact)) {
                continue;
            }
            $waId = (string) ($contact['wa_id'] ?? '');
            if ($waId !== '') {
                $profiles[$waId] = isset($contact['profile']['name']) ? (string) $contact['profile']['name'] : null;
            }
        }

        return $profiles;
    }

    /**
     * @param  array<string, mixed>  $messageData
     * @param  array<string, string|null>  $profilesByWaId
     */
    private function ingestInboundMessage(WhatsAppChannel $channel, array $messageData, array $profilesByWaId): string
    {
        $wamid = isset($messageData['id']) ? (string) $messageData['id'] : null;
        $fromRaw = (string) ($messageData['from'] ?? '');

        if ($fromRaw === '') {
            return 'ignored';
        }

        if ($wamid !== null && WhatsAppMessage::query()->where('wamid', $wamid)->exists()) {
            return 'duplicate';
        }

        $phoneE164 = PhoneNumberNormalizer::toE164($fromRaw);
        $contact = WhatsAppContact::query()->firstOrCreate(
            ['phone_e164' => $phoneE164],
            [
                'wa_id' => $fromRaw,
                'profile_name' => $profilesByWaId[$fromRaw] ?? null,
            ],
        );

        $receivedAt = isset($messageData['timestamp']) && is_numeric($messageData['timestamp'])
            ? Carbon::createFromTimestampUTC((int) $messageData['timestamp'])
            : now()->utc();

        $messageType = (string) ($messageData['type'] ?? 'unknown');
        if (! in_array($messageType, ['text', 'interactive', 'location', 'template', 'status'], true)) {
            $messageType = 'unknown';
        }

        try {
            $record = WhatsAppMessage::query()->create([
                'whatsapp_channel_id' => $channel->id,
                'tenant_id' => $channel->tenant_id,
                'whatsapp_contact_id' => $contact->id,
                'direction' => 'inbound',
                'wamid' => $wamid,
                'message_type' => $messageType,
                'delivery_status' => 'queued',
                'payload_redacted' => $this->redactor->redact($messageData),
                'received_at' => $receivedAt,
            ]);
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                return 'duplicate';
            }

            throw $e;
        }

        ProcessInboundWhatsAppMessageJob::dispatch($record->id);

        return 'queued';
    }

    /**
     * @param  array<string, mixed>  $statusData
     */
    private function processStatusUpdate(WhatsAppChannel $channel, array $statusData): bool
    {
        $wamid = (string) ($statusData['id'] ?? '');
        $newStatus = (string) ($statusData['status'] ?? '');

        if ($wamid === '' || $newStatus === '') {
            return false;
        }

        $message = WhatsAppMessage::query()
            ->where('whatsapp_channel_id', $channel->id)
            ->where('wamid', $wamid)
            ->first();

        if ($message === null) {
            return false;
        }

        $currentRank = self::STATUS_RANK[(string) $message->delivery_status] ?? 0;
        $newRank = self::STATUS_RANK[$newStatus] ?? 0;

        // Avoid downgrading status on out-of-order Meta delivery callbacks.
        if ($newRank < $currentRank) {
            return false;
        }

        $errorMessage = null;
        if ($newStatus === 'failed' && ! empty($statusData['errors'][0]['message'])) {
            $errorMessage = (string) $statusData['errors'][0]['message'];
        }

        $message->update([
            'delivery_status' => $newStatus,
            'error_message' => $errorMessage ?? $message->error_message,
        ]);

        return true;
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        $sqlState = $e->errorInfo[0] ?? null;

        return in_array($sqlState, ['23505', '23000'], true);
    }
}
