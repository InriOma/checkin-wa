<?php

declare(strict_types=1);

use App\Domains\Attendance\Enums\AttendanceStatus;
use App\Domains\Attendance\Models\AttendanceRecord;
use App\Domains\Branches\Models\Branch;
use App\Domains\Employees\Models\Employee;
use App\Domains\Identity\Enums\TenantRole;
use App\Domains\Identity\Models\TenantMembership;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\WhatsApp\Models\EmployeeWhatsAppContact;
use App\Domains\WhatsApp\Models\PendingAction;
use App\Domains\WhatsApp\Models\WhatsAppChannel;
use App\Domains\WhatsApp\Models\WhatsAppContact;
use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

function sendSignedWhatsAppWebhook(array $payload, string $secret = 'att_webhook_secret')
{
    $raw = json_encode($payload, JSON_THROW_ON_ERROR);

    return test()->call('POST', '/api/v1/webhooks/whatsapp', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw, $secret),
    ], $raw);
}

it('executes full whatsapp attendance flow with location request, geofence, break, and api isolation', function (): void {
    Config::set('whatsapp.app_secret', 'att_webhook_secret');
    Config::set('whatsapp.location_ttl_seconds', 120);

    $outbound = [];
    Http::fake(function ($request) use (&$outbound) {
        $outbound[] = $request->data();

        return Http::response([
            'messaging_product' => 'whatsapp',
            'messages' => [['id' => 'wamid.wa.out.'.count($outbound)]],
        ], 200);
    });

    $tenant = Tenant::factory()->create(['code' => 'WAATT1', 'name' => 'Tienda Centro']);
    $otherTenant = Tenant::factory()->create(['code' => 'WAATT2', 'name' => 'Otra Empresa']);

    $branch = Branch::withoutGlobalScopes()->create([
        'tenant_id' => $tenant->id,
        'code' => 'S1',
        'name' => 'Sucursal Reforma',
        'latitude' => 19.42847,
        'longitude' => -99.16283,
        'geofence_radius_meters' => 100,
        'timezone' => 'America/Mexico_City',
        'status' => 'active',
    ]);

    $employee = Employee::withoutGlobalScopes()->create([
        'tenant_id' => $tenant->id,
        'employee_code' => 'EMP01',
        'first_name' => 'Ana',
        'last_name' => 'Garcia',
        'default_branch_id' => $branch->id,
        'status' => 'active',
    ]);

    WhatsAppChannel::query()->create([
        'tenant_id' => null,
        'channel_type' => 'central',
        'phone_number_id' => 'PHONE_CENTRAL_001',
        'business_account_id' => 'WABA_1001',
        'display_phone_number' => '+525500000001',
        'access_token_encrypted' => 'central-secret-token',
        'status' => 'active',
    ]);

    $contact = WhatsAppContact::query()->create([
        'phone_e164' => '+5215512345678',
        'wa_id' => '5215512345678',
    ]);

    EmployeeWhatsAppContact::withoutGlobalScopes()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'whatsapp_contact_id' => $contact->id,
        'verified_at' => now()->utc(),
    ]);

    $textFixture = json_decode(file_get_contents(base_path('tests/Fixtures/WhatsApp/inbound_text.json')), true);
    $locationFixture = json_decode(file_get_contents(base_path('tests/Fixtures/WhatsApp/inbound_location.json')), true);

    // 1. Employee asks to clock in -> receives location_request_message
    $textFixture['entry'][0]['changes'][0]['value']['messages'][0]['id'] = 'wamid.FLOW_1';
    $textFixture['entry'][0]['changes'][0]['value']['messages'][0]['text']['body'] = 'ENTRADA';
    sendSignedWhatsAppWebhook($textFixture)->assertOk();

    expect($outbound[0]['interactive']['type'] ?? null)->toBe('location_request_message')
        ->and(PendingAction::query()->where('action_type', 'awaiting_location_clock_in')->whereNull('consumed_at')->count())->toBe(1);

    // 2. Employee shares location inside geofence -> shift opens
    $locationFixture['entry'][0]['changes'][0]['value']['messages'][0]['id'] = 'wamid.FLOW_2_LOC';
    sendSignedWhatsAppWebhook($locationFixture)->assertOk();

    $record = AttendanceRecord::withoutGlobalScopes()->firstOrFail();
    expect($record->status)->toBe(AttendanceStatus::Working)
        ->and($record->branch_id)->toBe($branch->id)
        ->and($outbound[1]['text']['body'] ?? '')->toContain('Entrada registrada');

    // 3. Unsolicited second location without pending_action is rejected cleanly
    $locationFixture['entry'][0]['changes'][0]['value']['messages'][0]['id'] = 'wamid.FLOW_3_STALE_LOC';
    sendSignedWhatsAppWebhook($locationFixture)->assertOk();
    expect($outbound[2]['text']['body'] ?? '')->toContain('No tienes una solicitud de ubicación vigente');

    // 4. Break start & break end
    $textFixture['entry'][0]['changes'][0]['value']['messages'][0]['id'] = 'wamid.FLOW_4_BS';
    $textFixture['entry'][0]['changes'][0]['value']['messages'][0]['text']['body'] = 'DESCANSO';
    sendSignedWhatsAppWebhook($textFixture)->assertOk();
    expect($record->refresh()->status)->toBe(AttendanceStatus::OnBreak);

    $textFixture['entry'][0]['changes'][0]['value']['messages'][0]['id'] = 'wamid.FLOW_5_BE';
    $textFixture['entry'][0]['changes'][0]['value']['messages'][0]['text']['body'] = 'FIN DESCANSO';
    sendSignedWhatsAppWebhook($textFixture)->assertOk();
    expect($record->refresh()->status)->toBe(AttendanceStatus::Working);

    // 5. Clock out request -> location -> shift closed
    $textFixture['entry'][0]['changes'][0]['value']['messages'][0]['id'] = 'wamid.FLOW_6_OUT';
    $textFixture['entry'][0]['changes'][0]['value']['messages'][0]['text']['body'] = 'SALIDA';
    sendSignedWhatsAppWebhook($textFixture)->assertOk();
    expect($outbound[5]['interactive']['type'] ?? null)->toBe('location_request_message');

    $locationFixture['entry'][0]['changes'][0]['value']['messages'][0]['id'] = 'wamid.FLOW_7_OUT_LOC';
    sendSignedWhatsAppWebhook($locationFixture)->assertOk();
    expect($record->refresh()->status)->toBe(AttendanceStatus::Closed)
        ->and($record->events()->count())->toBe(4);

    // 6. API endpoint returns record for tenant owner and isolates from other tenant
    $owner = User::factory()->create();
    TenantMembership::query()->create([
        'tenant_id' => $tenant->id,
        'user_id' => $owner->id,
        'role' => TenantRole::CompanyOwner->value,
        'status' => 'active',
    ]);

    $otherOwner = User::factory()->create();
    TenantMembership::query()->create([
        'tenant_id' => $otherTenant->id,
        'user_id' => $otherOwner->id,
        'role' => TenantRole::CompanyOwner->value,
        'status' => 'active',
    ]);

    $this->actingAs($owner, 'sanctum')
        ->withHeader('X-Tenant-Id', (string) $tenant->id)
        ->getJson("/api/v1/attendance/{$record->id}")
        ->assertOk()
        ->assertJsonCount(4, 'data.events');

    $this->actingAs($otherOwner, 'sanctum')
        ->withHeader('X-Tenant-Id', (string) $otherTenant->id)
        ->getJson("/api/v1/attendance/{$record->id}")
        ->assertNotFound();
});

it('rejects expired pending location action', function (): void {
    Config::set('whatsapp.app_secret', 'att_webhook_secret');

    $outbound = [];
    Http::fake(function ($request) use (&$outbound) {
        $outbound[] = $request->data();

        return Http::response(['messages' => [['id' => 'wamid.exp.'.count($outbound)]]], 200);
    });

    $tenant = Tenant::factory()->create(['code' => 'EXP01']);
    $branch = Branch::withoutGlobalScopes()->create([
        'tenant_id' => $tenant->id,
        'code' => 'S1',
        'name' => 'Sucursal',
        'latitude' => 19.42847,
        'longitude' => -99.16283,
        'geofence_radius_meters' => 100,
        'timezone' => 'America/Mexico_City',
        'status' => 'active',
    ]);

    $employee = Employee::withoutGlobalScopes()->create([
        'tenant_id' => $tenant->id,
        'employee_code' => 'EMP01',
        'first_name' => 'Ana',
        'last_name' => 'Garcia',
        'default_branch_id' => $branch->id,
        'status' => 'active',
    ]);

    $channel = WhatsAppChannel::query()->create([
        'tenant_id' => null,
        'channel_type' => 'central',
        'phone_number_id' => 'PHONE_CENTRAL_001',
        'business_account_id' => 'WABA_1001',
        'display_phone_number' => '+525500000001',
        'access_token_encrypted' => 'secret',
        'status' => 'active',
    ]);

    $contact = WhatsAppContact::query()->create([
        'phone_e164' => '+5215512345678',
        'wa_id' => '5215512345678',
    ]);

    EmployeeWhatsAppContact::withoutGlobalScopes()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'whatsapp_contact_id' => $contact->id,
        'verified_at' => now()->utc(),
    ]);

    PendingAction::query()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'whatsapp_channel_id' => $channel->id,
        'whatsapp_contact_id' => $contact->id,
        'action_type' => 'awaiting_location_clock_in',
        'expires_at' => now()->utc()->subSecond(),
    ]);

    $locationFixture = json_decode(file_get_contents(base_path('tests/Fixtures/WhatsApp/inbound_location.json')), true);
    $locationFixture['entry'][0]['changes'][0]['value']['messages'][0]['id'] = 'wamid.EXP_LOC';
    sendSignedWhatsAppWebhook($locationFixture)->assertOk();

    expect(AttendanceRecord::withoutGlobalScopes()->count())->toBe(0)
        ->and($outbound[0]['text']['body'] ?? '')->toContain('expiró');
});
