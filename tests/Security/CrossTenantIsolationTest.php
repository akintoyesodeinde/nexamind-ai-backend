<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Domain\Audit\Contracts\AuditLoggerInterface;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Audit\Models\SecurityEvent;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Services\TenantService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class CrossTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_tenant_a_cannot_access_tenant_b_resources_even_with_valid_uuids(): void
    {
        /** @var TenantService $tenantService */
        $tenantService = app(TenantService::class);

        $ownerA = User::factory()->create(['email' => 'owner-a@tenant-a.test']);
        $ownerB = User::factory()->create(['email' => 'owner-b@tenant-b.test']);

        $tenantA = $tenantService->createTenant($ownerA, 'Tenant Alpha', 'tenant-alpha');
        $tenantB = $tenantService->createTenant($ownerB, 'Tenant Beta', 'tenant-beta');

        $memberB = $tenantService->addMember(
            tenant: $tenantB['tenant'],
            actor: $ownerB,
            actorRoleSlug: 'owner',
            email: 'secret-staff@tenant-b.test',
            name: 'Secret Staff',
            roleSlug: 'member',
        );

        $tokenA = $ownerA->createToken('test-a')->plainTextToken;

        // 1. Tenant A sends Tenant B's valid X-Tenant-ID -> MUST BE 403 FORBIDDEN
        $this->withToken($tokenA)
            ->withHeader('X-Tenant-ID', $tenantB['tenant']->id)
            ->getJson('/api/v1/tenants/current')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'FORBIDDEN');

        $this->withToken($tokenA)
            ->withHeader('X-Tenant-ID', $tenantB['tenant']->id)
            ->getJson('/api/v1/tenants/current/members')
            ->assertForbidden();

        $this->withToken($tokenA)
            ->withHeader('X-Tenant-ID', $tenantB['tenant']->id)
            ->getJson('/api/v1/audit-logs')
            ->assertForbidden();

        // 2. Tenant A sends own X-Tenant-ID but targets Tenant B's valid membership UUID -> MUST BE 404
        $this->withToken($tokenA)
            ->withHeader('X-Tenant-ID', $tenantA['tenant']->id)
            ->patchJson("/api/v1/tenants/current/members/{$memberB['id']}", ['role_slug' => 'viewer'])
            ->assertNotFound();

        $this->withToken($tokenA)
            ->withHeader('X-Tenant-ID', $tenantA['tenant']->id)
            ->deleteJson("/api/v1/tenants/current/members/{$memberB['id']}")
            ->assertNotFound();

        // 3. Cross-tenant attempt MUST be logged in security_events
        $this->assertTrue(
            SecurityEvent::query()
                ->where('event_type', 'tenancy.cross_tenant_denied')
                ->where('actor_user_id', $ownerA->id)
                ->exists(),
        );
    }

    public function test_rbac_prevents_viewer_member_and_admin_privilege_escalation(): void
    {
        /** @var TenantService $tenantService */
        $tenantService = app(TenantService::class);

        $owner = User::factory()->create(['email' => 'owner@org.test']);
        $admin = User::factory()->create(['email' => 'admin@org.test']);
        $viewer = User::factory()->create(['email' => 'viewer@org.test']);

        $org = $tenantService->createTenant($owner, 'RBAC Org', 'rbac-org');
        $tenantId = $org['tenant']->id;

        $tenantService->addMember($org['tenant'], $owner, 'owner', $admin->email, null, 'admin');
        $tenantService->addMember($org['tenant'], $owner, 'owner', $viewer->email, null, 'viewer');

        $viewerToken = $viewer->createToken('viewer')->plainTextToken;
        $adminToken = $admin->createToken('admin')->plainTextToken;

        // Viewer denied settings, members, audit logs
        $this->withToken($viewerToken)
            ->withHeader('X-Tenant-ID', $tenantId)
            ->patchJson('/api/v1/tenants/current/settings', ['max_upload_mb' => 50])
            ->assertForbidden();

        $this->withToken($viewerToken)
            ->withHeader('X-Tenant-ID', $tenantId)
            ->getJson('/api/v1/tenants/current/members')
            ->assertForbidden();

        $this->withToken($viewerToken)
            ->withHeader('X-Tenant-ID', $tenantId)
            ->getJson('/api/v1/audit-logs')
            ->assertForbidden();

        // Reset cached guard user before switching bearer token inside the same test case
        $this->app['auth']->forgetGuards();

        // Admin CANNOT assign Owner role (privilege escalation blocked)
        $this->withToken($adminToken)
            ->withHeader('X-Tenant-ID', $tenantId)
            ->postJson('/api/v1/tenants/current/members', [
                'email' => 'escalated@org.test',
                'role_slug' => 'owner',
            ])
            ->assertForbidden();

        // Admin CANNOT demote Owner
        $this->withToken($adminToken)
            ->withHeader('X-Tenant-ID', $tenantId)
            ->patchJson("/api/v1/tenants/current/members/{$org['membership']->id}", [
                'role_slug' => 'member',
            ])
            ->assertForbidden();

        $this->assertTrue(
            SecurityEvent::query()
                ->where('event_type', 'rbac.privilege_escalation_blocked')
                ->where('actor_user_id', $admin->id)
                ->exists(),
        );
    }

    public function test_audit_log_hash_chain_detects_tampering(): void
    {
        /** @var TenantService $tenantService */
        $tenantService = app(TenantService::class);
        /** @var AuditLoggerInterface $auditLogger */
        $auditLogger = app(AuditLoggerInterface::class);

        $owner = User::factory()->create(['email' => 'audit-owner@org.test']);
        $org = $tenantService->createTenant($owner, 'Audit Chain Org', 'audit-chain-org');
        $tenantId = $org['tenant']->id;

        $tenantService->updateSettings($org['tenant'], $owner, ['max_upload_mb' => 15]);

        $this->assertTrue($auditLogger->verifyTenantChain($tenantId));

        // Simulate direct DB tampering on an audit record
        /** @var AuditLog $first */
        $first = AuditLog::query()->where('tenant_id', $tenantId)->orderBy('created_at')->firstOrFail();
        DB::table('audit_logs')
            ->where('id', $first->id)
            ->update(['action' => 'tampered.action']);

        $this->assertFalse($auditLogger->verifyTenantChain($tenantId));
    }
}
