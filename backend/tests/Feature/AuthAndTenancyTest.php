<?php

declare(strict_types=1);

use App\Domains\Branches\Models\Branch;
use App\Domains\Identity\Enums\TenantRole;
use App\Domains\Identity\Models\TenantMembership;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

it('authenticates dashboard users with sanctum and lists authorized tenants only', function (): void {
    $tenantA = Tenant::factory()->create(['code' => 'ACME01', 'name' => 'Acme Corp']);
    $tenantB = Tenant::factory()->create(['code' => 'BETA01', 'name' => 'Beta Corp']);

    $user = User::factory()->create([
        'email' => 'owner@acme.local',
        'password' => Hash::make('Secret123!'),
    ]);

    TenantMembership::query()->create([
        'tenant_id' => $tenantA->id,
        'user_id' => $user->id,
        'role' => TenantRole::CompanyOwner->value,
        'status' => 'active',
    ]);

    $loginResponse = $this->postJson('/api/v1/auth/login', [
        'email' => 'owner@acme.local',
        'password' => 'Secret123!',
    ]);

    $loginResponse->assertOk()
        ->assertJsonStructure(['token', 'token_type', 'user' => ['id', 'email', 'memberships']]);

    $token = $loginResponse->json('token');

    $tenantsResponse = $this->withToken($token)->getJson('/api/v1/auth/tenants');
    $tenantsResponse->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.code', 'ACME01');

    // User cannot spoof X-Tenant-Id for a tenant where they have no membership
    $this->withToken($token)
        ->withHeader('X-Tenant-Id', (string) $tenantB->id)
        ->getJson('/api/v1/tenant')
        ->assertForbidden();
});

it('requires explicit tenant header when user belongs to multiple active tenants', function (): void {
    $tenantA = Tenant::factory()->create(['code' => 'ACME02']);
    $tenantB = Tenant::factory()->create(['code' => 'BETA02']);

    $user = User::factory()->create();

    TenantMembership::query()->create([
        'tenant_id' => $tenantA->id,
        'user_id' => $user->id,
        'role' => TenantRole::HrAdmin->value,
        'status' => 'active',
    ]);
    TenantMembership::query()->create([
        'tenant_id' => $tenantB->id,
        'user_id' => $user->id,
        'role' => TenantRole::HrAdmin->value,
        'status' => 'active',
    ]);

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/tenant')
        ->assertStatus(422);

    $this->actingAs($user, 'sanctum')
        ->withHeader('X-Tenant-Code', 'BETA02')
        ->getJson('/api/v1/tenant')
        ->assertOk()
        ->assertJsonPath('data.code', 'BETA02');
});

it('enforces rbac permissions across roles', function (): void {
    $tenant = Tenant::factory()->create(['code' => 'ROLE01']);

    $employeeUser = User::factory()->create();
    TenantMembership::query()->create([
        'tenant_id' => $tenant->id,
        'user_id' => $employeeUser->id,
        'role' => TenantRole::Employee->value,
        'status' => 'active',
    ]);

    $this->actingAs($employeeUser, 'sanctum')
        ->withHeader('X-Tenant-Id', (string) $tenant->id)
        ->postJson('/api/v1/branches', [
            'code' => 'BR01',
            'name' => 'Sucursal Uno',
            'latitude' => 19.4326,
            'longitude' => -99.1332,
            'geofence_radius_meters' => 100,
            'timezone' => 'America/Mexico_City',
        ])
        ->assertForbidden();

    $hrUser = User::factory()->create();
    TenantMembership::query()->create([
        'tenant_id' => $tenant->id,
        'user_id' => $hrUser->id,
        'role' => TenantRole::HrAdmin->value,
        'status' => 'active',
    ]);

    $this->actingAs($hrUser, 'sanctum')
        ->withHeader('X-Tenant-Id', (string) $tenant->id)
        ->postJson('/api/v1/branches', [
            'code' => 'BR01',
            'name' => 'Sucursal Uno',
            'latitude' => 19.4326,
            'longitude' => -99.1332,
            'geofence_radius_meters' => 100,
            'timezone' => 'America/Mexico_City',
        ])
        ->assertCreated()
        ->assertJsonPath('data.code', 'BR01');
});

it('scopes branch_manager visibility to assigned branches only', function (): void {
    $tenant = Tenant::factory()->create(['code' => 'SCOPE1']);
    $branch1 = Branch::factory()->create(['tenant_id' => $tenant->id, 'code' => 'B1']);
    $branch2 = Branch::factory()->create(['tenant_id' => $tenant->id, 'code' => 'B2']);

    $manager = User::factory()->create();
    TenantMembership::query()->create([
        'tenant_id' => $tenant->id,
        'user_id' => $manager->id,
        'role' => TenantRole::BranchManager->value,
        'status' => 'active',
        'branch_scope_ids' => [$branch1->id],
    ]);

    $this->actingAs($manager, 'sanctum')
        ->withHeader('X-Tenant-Id', (string) $tenant->id)
        ->getJson('/api/v1/branches')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $branch1->id);

    $this->actingAs($manager, 'sanctum')
        ->withHeader('X-Tenant-Id', (string) $tenant->id)
        ->getJson("/api/v1/branches/{$branch2->id}")
        ->assertForbidden();
});

it('exposes openapi documentation via scramble', function (): void {
    $response = $this->getJson('/docs/api.json')
        ->assertOk()
        ->assertJsonStructure(['openapi', 'info', 'paths']);

    expect((string) $response->json('openapi'))->toStartWith('3.1');
    expect(array_keys($response->json('paths')))->toContain('/v1/branches', '/v1/employees', '/v1/schedules');
});
