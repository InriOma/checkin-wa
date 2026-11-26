<?php

declare(strict_types=1);

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Employees\Models\Employee;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\WhatsApp\Models\EmployeeWhatsAppContact;
use App\Domains\WhatsApp\Models\WhatsAppChannel;
use App\Domains\WhatsApp\Models\WhatsAppContact;
use App\Domains\WhatsApp\Models\WhatsAppLinkOtp;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Config::set('whatsapp.app_secret', 'test_secret_hybrid');
    Config::set('whatsapp.graph_version', 'v21.0');
    Config::set('whatsapp.graph_base_url', 'https://graph.facebook.com');
    Config::set('whatsapp.otp_rate_limit_per_hour', 5);
});

function sendSignedFixtureOrPayload(array $payload, string $secret = 'test_secret_hybrid')
{
    $raw = json_encode($payload, JSON_THROW_ON_ERROR);
    $sig = 'sha256='.hash_hmac('sha256', $raw, $secret);

    return test()->call('POST', '/api/v1/webhooks/whatsapp', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => $sig,
    ], $raw);
}

it('completes central channel onboarding with otp, attempt limit, and audit log', function (): void {
    $capturedBodies = [];
    Http::fake(function ($request) use (&$capturedBodies) {
        $capturedBodies[] = $request->data();

        return Http::response([
            'messaging_product' => 'whatsapp',
            'messages' => [['id' => 'wamid.out.'.count($capturedBodies)]],
        ], 200);
    });

    $tenant = Tenant::factory()->create(['code' => 'ACME01', 'name' => 'Acme MX']);
    $employee = Employee::withoutGlobalScopes()->create([
        'tenant_id' => $tenant->id,
        'employee_code' => 'EMP001',
        'first_name' => 'Ana',
        'last_name' => 'Garcia',
        'status' => 'active',
    ]);

    $centralChannel = WhatsAppChannel::query()->create([
        'tenant_id' => null,
        'channel_type' => 'central',
        'phone_number_id' => 'PHONE_CENTRAL_001',
        'business_account_id' => 'WABA_1001',
        'display_phone_number' => '+525500000001',
        'access_token_encrypted' => 'central-token-xyz',
        'status' => 'active',
    ]);

    // Step 1: Employee initiates linking on central channel
    $inbound = json_decode(file_get_contents(base_path('tests/Fixtures/WhatsApp/inbound_text.json')), true);
    sendSignedFixtureOrPayload($inbound)->assertOk();

    $otpMessageText = (string) ($capturedBodies[0]['text']['body'] ?? '');
    preg_match('/\b(\d{6})\b/', $otpMessageText, $m);
    expect($m)->toHaveCount(2);
    $otpCode = $m[1];

    // Step 2: Wrong OTP increments attempts
    $wrongOtp = $otpCode === '111111' ? '222222' : '111111';
    $inbound['entry'][0]['changes'][0]['value']['messages'][0]['id'] = 'wamid.OTP_WRONG_1';
    $inbound['entry'][0]['changes'][0]['value']['messages'][0]['text']['body'] = $wrongOtp;
    sendSignedFixtureOrPayload($inbound)->assertOk();

    $otpRecord = WhatsAppLinkOtp::withoutGlobalScopes()->firstOrFail();
    expect($otpRecord->attempts)->toBe(1)
        ->and($otpRecord->consumed_at)->toBeNull();

    // Step 3: Valid OTP verifies membership and writes audit log
    $inbound['entry'][0]['changes'][0]['value']['messages'][0]['id'] = 'wamid.OTP_VALID_1';
    $inbound['entry'][0]['changes'][0]['value']['messages'][0]['text']['body'] = $otpCode;
    sendSignedFixtureOrPayload($inbound)->assertOk();

    $link = EmployeeWhatsAppContact::withoutGlobalScopes()
        ->where('tenant_id', $tenant->id)
        ->where('employee_id', $employee->id)
        ->first();

    expect($link)->not->toBeNull()
        ->and($link->verified_at)->not->toBeNull()
        ->and($link->linked_via_channel_id)->toBe($centralChannel->id);

    expect(AuditLog::withoutGlobalScopes()->where('action', 'whatsapp.contact_linked')->count())->toBe(1);
});

it('routes dedicated channel directly to its tenant and disambiguates multi-tenant central contacts', function (): void {
    $capturedBodies = [];
    Http::fake(function ($request) use (&$capturedBodies) {
        $capturedBodies[] = $request->data();

        return Http::response([
            'messaging_product' => 'whatsapp',
            'messages' => [['id' => 'wamid.out.'.count($capturedBodies)]],
        ], 200);
    });

    $tenantA = Tenant::factory()->create(['code' => 'TENA', 'name' => 'Alpha SA']);
    $tenantB = Tenant::factory()->create(['code' => 'TENB', 'name' => 'Beta SA']);

    $empA = Employee::withoutGlobalScopes()->create([
        'tenant_id' => $tenantA->id,
        'employee_code' => 'E1',
        'first_name' => 'Luis',
        'last_name' => 'Uno',
        'status' => 'active',
    ]);
    $empB = Employee::withoutGlobalScopes()->create([
        'tenant_id' => $tenantB->id,
        'employee_code' => 'E2',
        'first_name' => 'Luis',
        'last_name' => 'Dos',
        'status' => 'active',
    ]);

    $contact = WhatsAppContact::query()->create([
        'phone_e164' => '+5215512345678',
        'wa_id' => '5215512345678',
    ]);

    EmployeeWhatsAppContact::withoutGlobalScopes()->create([
        'tenant_id' => $tenantA->id,
        'employee_id' => $empA->id,
        'whatsapp_contact_id' => $contact->id,
        'verified_at' => now(),
    ]);
    EmployeeWhatsAppContact::withoutGlobalScopes()->create([
        'tenant_id' => $tenantB->id,
        'employee_id' => $empB->id,
        'whatsapp_contact_id' => $contact->id,
        'verified_at' => now(),
    ]);

    WhatsAppChannel::query()->create([
        'tenant_id' => null,
        'channel_type' => 'central',
        'phone_number_id' => 'PHONE_CENTRAL_001',
        'business_account_id' => 'WABA_CENTRAL',
        'display_phone_number' => '+525500000001',
        'access_token_encrypted' => 'central-token',
        'status' => 'active',
    ]);

    WhatsAppChannel::query()->create([
        'tenant_id' => $tenantA->id,
        'channel_type' => 'dedicated',
        'phone_number_id' => 'PHONE_DED_A',
        'business_account_id' => 'WABA_A',
        'display_phone_number' => '+525500000002',
        'access_token_encrypted' => 'dedicated-token-a',
        'status' => 'active',
    ]);

    // 1) Central channel sees 2 memberships and asks for company selection
    $centralPayload = json_decode(file_get_contents(base_path('tests/Fixtures/WhatsApp/inbound_text.json')), true);
    $centralPayload['entry'][0]['changes'][0]['value']['messages'][0]['id'] = 'wamid.MULTI_1';
    $centralPayload['entry'][0]['changes'][0]['value']['messages'][0]['text']['body'] = 'Hola';
    sendSignedFixtureOrPayload($centralPayload)->assertOk();

    expect($capturedBodies[0]['interactive']['type'] ?? null)->toBe('button')
        ->and($capturedBodies[0]['interactive']['action']['buttons'][0]['reply']['id'])->toBe("select_tenant:{$tenantA->id}");

    // 2) Interactive selection selects Tenant B cleanly
    $interactivePayload = json_decode(file_get_contents(base_path('tests/Fixtures/WhatsApp/inbound_interactive_button.json')), true);
    $interactivePayload['entry'][0]['changes'][0]['value']['messages'][0]['interactive']['button_reply']['id'] = "select_tenant:{$tenantB->id}";
    sendSignedFixtureOrPayload($interactivePayload)->assertOk();

    expect($capturedBodies[1]['interactive']['body']['text'] ?? '')->toContain('Beta SA');

    // 3) Dedicated channel resolves Tenant A directly without selection prompt
    $dedPayload = json_decode(file_get_contents(base_path('tests/Fixtures/WhatsApp/inbound_text.json')), true);
    $dedPayload['entry'][0]['changes'][0]['value']['metadata']['phone_number_id'] = 'PHONE_DED_A';
    $dedPayload['entry'][0]['changes'][0]['value']['messages'][0]['id'] = 'wamid.DED_1';
    $dedPayload['entry'][0]['changes'][0]['value']['messages'][0]['text']['body'] = 'Hola';
    sendSignedFixtureOrPayload($dedPayload)->assertOk();

    expect($capturedBodies[2]['interactive']['body']['text'] ?? '')->toContain('Alpha SA');
});

it('enforces rate limit on repeated otp linking requests', function (): void {
    $outCount = 0;
    Http::fake(function () use (&$outCount) {
        $outCount++;

        return Http::response(['messages' => [['id' => "wamid.rl.{$outCount}"]]], 200);
    });

    Config::set('whatsapp.otp_rate_limit_per_hour', 2);

    $tenant = Tenant::factory()->create(['code' => 'RL01']);
    Employee::withoutGlobalScopes()->create([
        'tenant_id' => $tenant->id,
        'employee_code' => 'EMP01',
        'first_name' => 'Mario',
        'last_name' => 'Ruiz',
        'status' => 'active',
    ]);

    WhatsAppChannel::query()->create([
        'tenant_id' => null,
        'channel_type' => 'central',
        'phone_number_id' => 'PHONE_CENTRAL_001',
        'business_account_id' => 'WABA_RL',
        'display_phone_number' => '+525500000001',
        'access_token_encrypted' => 'token-rl',
        'status' => 'active',
    ]);

    $payload = json_decode(file_get_contents(base_path('tests/Fixtures/WhatsApp/inbound_text.json')), true);

    foreach ([1, 2, 3] as $idx) {
        $payload['entry'][0]['changes'][0]['value']['messages'][0]['id'] = "wamid.RL_{$idx}";
        $payload['entry'][0]['changes'][0]['value']['messages'][0]['text']['body'] = 'VINCULAR RL01 EMP01';
        sendSignedFixtureOrPayload($payload)->assertOk();
    }

    // Only 2 OTPs created before rate limiter blocks the 3rd attempt
    expect(WhatsAppLinkOtp::withoutGlobalScopes()->count())->toBe(2);
});

it('locks otp verification after reaching max_attempts', function (): void {
    $capturedBodies = [];
    Http::fake(function ($request) use (&$capturedBodies) {
        $capturedBodies[] = $request->data();

        return Http::response([
            'messaging_product' => 'whatsapp',
            'messages' => [['id' => 'wamid.lock.'.count($capturedBodies)]],
        ], 200);
    });

    Config::set('whatsapp.otp_max_attempts', 2);

    $tenant = Tenant::factory()->create(['code' => 'LCK01']);
    Employee::withoutGlobalScopes()->create([
        'tenant_id' => $tenant->id,
        'employee_code' => 'EMP01',
        'first_name' => 'Sofia',
        'last_name' => 'Mendez',
        'status' => 'active',
    ]);

    WhatsAppChannel::query()->create([
        'tenant_id' => null,
        'channel_type' => 'central',
        'phone_number_id' => 'PHONE_CENTRAL_001',
        'business_account_id' => 'WABA_LCK',
        'display_phone_number' => '+525500000001',
        'access_token_encrypted' => 'token-lck',
        'status' => 'active',
    ]);

    $payload = json_decode(file_get_contents(base_path('tests/Fixtures/WhatsApp/inbound_text.json')), true);
    $payload['entry'][0]['changes'][0]['value']['messages'][0]['id'] = 'wamid.LCK_INIT';
    $payload['entry'][0]['changes'][0]['value']['messages'][0]['text']['body'] = 'VINCULAR LCK01 EMP01';
    sendSignedFixtureOrPayload($payload)->assertOk();

    preg_match('/\b(\d{6})\b/', (string) ($capturedBodies[0]['text']['body'] ?? ''), $m);
    $realOtp = $m[1];
    $wrongOtp = $realOtp === '000000' ? '999999' : '000000';

    foreach ([1, 2] as $attempt) {
        $payload['entry'][0]['changes'][0]['value']['messages'][0]['id'] = "wamid.LCK_BAD_{$attempt}";
        $payload['entry'][0]['changes'][0]['value']['messages'][0]['text']['body'] = $wrongOtp;
        sendSignedFixtureOrPayload($payload)->assertOk();
    }

    // Even the real OTP must now fail because max_attempts (2) was reached
    $payload['entry'][0]['changes'][0]['value']['messages'][0]['id'] = 'wamid.LCK_REAL_AFTER';
    $payload['entry'][0]['changes'][0]['value']['messages'][0]['text']['body'] = $realOtp;
    sendSignedFixtureOrPayload($payload)->assertOk();

    expect(EmployeeWhatsAppContact::withoutGlobalScopes()->count())->toBe(0);
});
