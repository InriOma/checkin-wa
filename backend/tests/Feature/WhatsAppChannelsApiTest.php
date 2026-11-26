<?php

declare(strict_types=1);

use App\Domains\Identity\Enums\TenantRole;
use App\Domains\Identity\Models\TenantMembership;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\WhatsApp\Models\WhatsAppChannel;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('isolates dedicated whatsapp channels per tenant and never exposes encrypted tokens', function (): void {
    $tenantA = Tenant::factory()->create(['code' => 'WAA']);
    $tenantB = Tenant::factory()->create(['code' => 'WAB']);

    $ownerA = User::factory()->create();
    TenantMembership::query()->create([
        'tenant_id' => $tenantA->id,
        'user_id' => $ownerA->id,
        'role' => TenantRole::CompanyOwner->value,
        'status' => 'active',
    ]);

    // Company owner cannot create a central channel
    $this->actingAs($ownerA, 'sanctum')
        ->withHeader('X-Tenant-Id', (string) $tenantA->id)
        ->postJson('/api/v1/whatsapp/channels', [
            'channel_type' => 'central',
            'phone_number_id' => 'PN_CENTRAL_BAD',
            'business_account_id' => 'WABA_1',
            'display_phone_number' => '+525500000010',
            'access_token' => 'super-secret-token-1234',
        ])
        ->assertStatus(422);

    // Company owner can register dedicated channel for own tenant without token leakage
    $resp = $this->actingAs($ownerA, 'sanctum')
        ->withHeader('X-Tenant-Id', (string) $tenantA->id)
        ->postJson('/api/v1/whatsapp/channels', [
            'channel_type' => 'dedicated',
            'phone_number_id' => 'PN_DED_A',
            'business_account_id' => 'WABA_A',
            'display_phone_number' => '+525500000011',
            'access_token' => 'super-secret-token-1234',
        ]);

    $resp->assertCreated()
        ->assertJsonPath('data.tenant_id', $tenantA->id)
        ->assertJsonPath('data.token_configured', true)
        ->assertJsonMissing(['access_token_encrypted' => 'super-secret-token-1234']);

    $channelB = WhatsAppChannel::query()->create([
        'tenant_id' => $tenantB->id,
        'channel_type' => 'dedicated',
        'phone_number_id' => 'PN_DED_B',
        'business_account_id' => 'WABA_B',
        'display_phone_number' => '+525500000012',
        'access_token_encrypted' => 'tenant-b-secret-token',
        'status' => 'active',
    ]);

    $this->actingAs($ownerA, 'sanctum')
        ->withHeader('X-Tenant-Id', (string) $tenantA->id)
        ->getJson("/api/v1/whatsapp/channels/{$channelB->id}")
        ->assertForbidden();
});

it('enforces central vs dedicated tenant constraint in postgresql', function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL CHECK constraint test.');
    }

    expect(fn () => DB::transaction(fn () => DB::table('whatsapp_channels')->insert([
        'tenant_id' => null,
        'channel_type' => 'dedicated',
        'phone_number_id' => 'PN_INVALID_CHECK',
        'business_account_id' => 'WABA_X',
        'display_phone_number' => '+525500000099',
        'access_token_encrypted' => 'enc',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ])))->toThrow(QueryException::class);
});
