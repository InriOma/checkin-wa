<?php

declare(strict_types=1);

use App\Domains\WhatsApp\Jobs\ProcessInboundWhatsAppMessageJob;
use App\Domains\WhatsApp\Models\WhatsAppChannel;
use App\Domains\WhatsApp\Models\WhatsAppMessage;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Config::set('whatsapp.verify_token', 'test_verify_token_123');
    Config::set('whatsapp.app_secret', 'test_app_secret_xyz');
});

function signWebhookPayload(string $rawJson, string $secret = 'test_app_secret_xyz'): string
{
    return 'sha256='.hash_hmac('sha256', $rawJson, $secret);
}

it('verifies whatsapp webhook subscription challenge with timing-safe token', function (): void {
    $this->get('/api/v1/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=test_verify_token_123&hub.challenge=CHALLENGE_999')
        ->assertOk()
        ->assertSeeText('CHALLENGE_999');

    $this->get('/api/v1/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=CHALLENGE_999')
        ->assertForbidden();
});

it('rejects post webhook with missing or invalid hmac signature', function (): void {
    $raw = file_get_contents(base_path('tests/Fixtures/WhatsApp/inbound_text.json'));

    $this->call('POST', '/api/v1/webhooks/whatsapp', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
    ], $raw)->assertUnauthorized();

    $this->call('POST', '/api/v1/webhooks/whatsapp', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256=deadbeef',
    ], $raw)->assertUnauthorized();
});

it('persists inbound messages idempotently by wamid, redacts pii, and queues once', function (): void {
    Queue::fake();

    WhatsAppChannel::query()->create([
        'tenant_id' => null,
        'channel_type' => 'central',
        'phone_number_id' => 'PHONE_CENTRAL_001',
        'business_account_id' => 'WABA_1001',
        'display_phone_number' => '+525500000001',
        'access_token_encrypted' => 'fake-meta-token-secret',
        'status' => 'active',
    ]);

    $raw = file_get_contents(base_path('tests/Fixtures/WhatsApp/inbound_text.json'));
    $signature = signWebhookPayload($raw);

    // First delivery -> queued
    $this->call('POST', '/api/v1/webhooks/whatsapp', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => $signature,
    ], $raw)
        ->assertOk()
        ->assertJsonPath('stats.queued_messages', 1)
        ->assertJsonPath('stats.duplicate_messages', 0);

    // Duplicate delivery with same wamid -> ignored safely
    $this->call('POST', '/api/v1/webhooks/whatsapp', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => $signature,
    ], $raw)
        ->assertOk()
        ->assertJsonPath('stats.queued_messages', 0)
        ->assertJsonPath('stats.duplicate_messages', 1);

    Queue::assertPushed(ProcessInboundWhatsAppMessageJob::class, 1);

    $msg = WhatsAppMessage::query()->where('wamid', 'wamid.HBgLNTIxNTUxMjM0NTY3OBUCABIYFjNFQjA1RkM5RjA=')->firstOrFail();
    expect($msg->payload_redacted['from'])->not->toBe('5215512345678')
        ->and($msg->payload_redacted['from'])->toContain('*');
});

it('handles status callbacks monotonically and ignores unknown phone_number_id', function (): void {
    Queue::fake();

    $channel = WhatsAppChannel::query()->create([
        'tenant_id' => null,
        'channel_type' => 'central',
        'phone_number_id' => 'PHONE_CENTRAL_001',
        'business_account_id' => 'WABA_1001',
        'display_phone_number' => '+525500000001',
        'access_token_encrypted' => 'fake-meta-token-secret',
        'status' => 'active',
    ]);

    $outbound = WhatsAppMessage::query()->create([
        'whatsapp_channel_id' => $channel->id,
        'direction' => 'outbound',
        'wamid' => 'wamid.OUTBOUND_001',
        'message_type' => 'text',
        'delivery_status' => 'sent',
        'payload_redacted' => ['body' => 'ok'],
        'received_at' => now()->utc(),
    ]);

    $statusRaw = file_get_contents(base_path('tests/Fixtures/WhatsApp/status_callback.json'));
    $this->call('POST', '/api/v1/webhooks/whatsapp', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => signWebhookPayload($statusRaw),
    ], $statusRaw)->assertOk()->assertJsonPath('stats.status_updates', 1);

    expect($outbound->refresh()->delivery_status)->toBe('delivered');

    // Out-of-order regression from read -> delivered must not downgrade
    $outbound->update(['delivery_status' => 'read']);
    $this->call('POST', '/api/v1/webhooks/whatsapp', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => signWebhookPayload($statusRaw),
    ], $statusRaw)->assertOk()->assertJsonPath('stats.status_updates', 0);

    expect($outbound->refresh()->delivery_status)->toBe('read');

    // Unknown phone_number_id is ignored cleanly
    $unknownRaw = file_get_contents(base_path('tests/Fixtures/WhatsApp/unknown_phone_number_id.json'));
    $this->call('POST', '/api/v1/webhooks/whatsapp', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => signWebhookPayload($unknownRaw),
    ], $unknownRaw)->assertOk()->assertJsonPath('stats.ignored_channels', 1);
});
