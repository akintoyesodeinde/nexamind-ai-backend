<?php

declare(strict_types=1);

namespace Tests\Feature;

use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AuthAndTenancyFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_health_endpoints_return_ok_and_sqlsrv_ready(): void
    {
        $this->getJson('/api/v1/health/live')
            ->assertOk()
            ->assertJsonPath('data.status', 'ok')
            ->assertHeader('X-Request-ID');

        $this->getJson('/api/v1/health/ready')
            ->assertOk()
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.database', 'connected');
    }

    public function test_full_registration_onboarding_settings_members_and_idempotency_flow(): void
    {
        // 1. Register user
        $reg = $this->withHeader('X-Client-Mode', 'api-token')
            ->postJson('/api/v1/auth/register', [
                'name' => 'Amara Owner',
                'email' => 'amara@acme.test',
                'job_title' => 'VP Engineering',
                'password' => 'SecurePass2026!',
                'password_confirmation' => 'SecurePass2026!',
            ])
            ->assertCreated()
            ->assertJsonPath('data.user.email', 'amara@acme.test');

        $token = (string) $reg->json('data.token');
        $this->assertNotEmpty($token);

        // 2. Create organization
        $tenantRes = $this->withToken($token)
            ->postJson('/api/v1/tenants', [
                'name' => 'Acme Compliance Corp',
                'slug' => 'acme-compliance',
            ])
            ->assertCreated()
            ->assertJsonPath('data.role', 'owner');

        $tenantId = (string) $tenantRes->json('data.tenant.id');

        // 3. Get current tenant & verify default DECIMAL(18,6) budget
        $this->withToken($token)
            ->withHeader('X-Tenant-ID', $tenantId)
            ->getJson('/api/v1/tenants/current')
            ->assertOk()
            ->assertJsonPath('data.tenant.slug', 'acme-compliance')
            ->assertJsonPath('data.settings.monthly_cost_budget_usd', '25.000000')
            ->assertJsonPath('data.current_role', 'owner');

        // 4. Update tenant settings
        $this->withToken($token)
            ->withHeader('X-Tenant-ID', $tenantId)
            ->patchJson('/api/v1/tenants/current/settings', [
                'monthly_token_budget' => 750000,
                'monthly_cost_budget_usd' => '49.950000',
                'max_upload_mb' => 25,
                'require_approval_for_external_actions' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.monthly_token_budget', 750000)
            ->assertJsonPath('data.monthly_cost_budget_usd', '49.950000');

        // 5. Add member with Idempotency-Key and replay identical request (0 duplicates!)
        $memberPayload = [
            'email' => 'manager@acme.test',
            'name' => 'Marcus Manager',
            'role_slug' => 'manager',
        ];

        $firstAdd = $this->withToken($token)
            ->withHeader('X-Tenant-ID', $tenantId)
            ->withHeader('Idempotency-Key', 'add-marcus-001')
            ->postJson('/api/v1/tenants/current/members', $memberPayload)
            ->assertCreated()
            ->assertJsonPath('data.role', 'manager');

        $membershipId = (string) $firstAdd->json('data.id');

        $this->withToken($token)
            ->withHeader('X-Tenant-ID', $tenantId)
            ->withHeader('Idempotency-Key', 'add-marcus-001')
            ->postJson('/api/v1/tenants/current/members', $memberPayload)
            ->assertCreated()
            ->assertHeader('X-Idempotency-Replayed', 'true')
            ->assertJsonPath('data.id', $membershipId);

        // Reusing same Idempotency-Key with different body must return 409 CONFLICT
        $this->withToken($token)
            ->withHeader('X-Tenant-ID', $tenantId)
            ->withHeader('Idempotency-Key', 'add-marcus-001')
            ->postJson('/api/v1/tenants/current/members', [
                'email' => 'different@acme.test',
                'role_slug' => 'viewer',
            ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'IDEMPOTENCY_CONFLICT');

        // 6. Update member role
        $this->withToken($token)
            ->withHeader('X-Tenant-ID', $tenantId)
            ->patchJson("/api/v1/tenants/current/members/{$membershipId}", [
                'role_slug' => 'admin',
            ])
            ->assertOk()
            ->assertJsonPath('data.role', 'admin');

        // 7. Prevent removing last active Owner
        $ownerMembershipId = (string) $tenantRes->json('data.membership_id');
        $this->withToken($token)
            ->withHeader('X-Tenant-ID', $tenantId)
            ->deleteJson("/api/v1/tenants/current/members/{$ownerMembershipId}")
            ->assertStatus(422);

        // 8. Remove non-owner member cleanly
        $this->withToken($token)
            ->withHeader('X-Tenant-ID', $tenantId)
            ->deleteJson("/api/v1/tenants/current/members/{$membershipId}")
            ->assertNoContent();

        // 9. Update profile
        $this->withToken($token)
            ->patchJson('/api/v1/me/profile', [
                'name' => 'Amara Updated',
                'job_title' => 'Chief Architect',
                'timezone' => 'Africa/Lagos',
                'locale' => 'en-GB',
                'preferences' => ['citations_expanded_by_default' => false],
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Amara Updated')
            ->assertJsonPath('data.timezone', 'Africa/Lagos');

        // 10. Audit logs contain tenant actions
        $this->withToken($token)
            ->withHeader('X-Tenant-ID', $tenantId)
            ->getJson('/api/v1/audit-logs')
            ->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['request_id', 'pagination']]);

        // 11. Logout revokes token
        $this->withToken($token)
            ->postJson('/api/v1/auth/logout')
            ->assertOk();
    }
}
