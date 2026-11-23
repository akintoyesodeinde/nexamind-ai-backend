<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Domain\Audit\Models\SecurityEvent;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Services\TenantService;
use App\Domain\Workflows\Models\ApprovalRequest;
use Database\Seeders\AiModelSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AgentToolSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RbacSeeder::class, AiModelSeeder::class]);
    }

    public function test_unlisted_tools_invalid_schemas_cross_tenant_kb_and_unapproved_publish_are_100_percent_blocked(): void
    {
        /** @var TenantService $tenantService */
        $tenantService = app(TenantService::class);

        $ownerA = User::factory()->create(['email' => 'agent-sec-a@acme.test']);
        $orgA = $tenantService->createTenant($ownerA, 'Agent Sec A', 'agent-sec-a');
        $tenantAId = $orgA['tenant']->id;
        $tokenA = $ownerA->createToken('sec-a')->plainTextToken;

        $ownerB = User::factory()->create(['email' => 'agent-sec-b@globex.test']);
        $orgB = $tenantService->createTenant($ownerB, 'Agent Sec B', 'agent-sec-b');
        $tenantBId = $orgB['tenant']->id;
        $tokenB = $ownerB->createToken('sec-b')->plainTextToken;

        // Create a KB in Tenant B
        $kbBId = (string) $this->withToken($tokenB)
            ->withHeader('X-Tenant-ID', $tenantBId)
            ->postJson('/api/v1/knowledge-bases', ['name' => 'Tenant B Secret KB', 'visibility' => 'tenant'])
            ->assertCreated()
            ->json('data.id');

        $this->app['auth']->forgetGuards();

        // 1. Attempting to configure an unlisted/dangerous tool (execute_sql) is 403 blocked & logged
        $this->withToken($tokenA)
            ->withHeader('X-Tenant-ID', $tenantAId)
            ->postJson('/api/v1/agents', [
                'name' => 'Rogue SQL Agent',
                'system_prompt' => 'Run arbitrary queries.',
                'tools' => ['search_knowledge_base', 'execute_sql'],
            ])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'UNLISTED_TOOL');

        $this->assertTrue(
            SecurityEvent::query()
                ->where('tenant_id', $tenantAId)
                ->where('event_type', 'agent.unlisted_tool_configured')
                ->where('severity', 'critical')
                ->exists(),
        );

        // Create a valid agent in Tenant A
        $agentAId = (string) $this->withToken($tokenA)
            ->withHeader('X-Tenant-ID', $tenantAId)
            ->postJson('/api/v1/agents', [
                'name' => 'Governed Compliance Agent',
                'system_prompt' => 'Only use approved safe tools.',
                'require_approval' => true,
            ])
            ->assertCreated()
            ->json('data.id');

        // 2. Attempting to invoke an unlisted OS shell tool at runtime is 403 blocked & logged
        $this->withToken($tokenA)
            ->withHeader('X-Tenant-ID', $tenantAId)
            ->postJson("/api/v1/agents/{$agentAId}/runs", [
                'goal' => 'Attempt shell command',
                'tool_call' => [
                    'name' => 'run_shell',
                    'arguments' => ['cmd' => 'cat /etc/passwd'],
                ],
            ])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'UNLISTED_TOOL');

        $this->assertTrue(
            SecurityEvent::query()
                ->where('tenant_id', $tenantAId)
                ->where('event_type', 'agent.unlisted_tool_blocked')
                ->exists(),
        );

        // 3. Invalid JSON Schema arguments (missing required 'query', extra field, invalid top_k) -> 422 blocked & logged
        $this->withToken($tokenA)
            ->withHeader('X-Tenant-ID', $tenantAId)
            ->postJson("/api/v1/agents/{$agentAId}/runs", [
                'goal' => 'Invalid schema arguments test',
                'tool_call' => [
                    'name' => 'search_knowledge_base',
                    'arguments' => ['top_k' => 999, 'unexpected_param' => 'malicious'],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'TOOL_SCHEMA_INVALID');

        $this->assertTrue(
            SecurityEvent::query()
                ->where('tenant_id', $tenantAId)
                ->where('event_type', 'agent.tool_schema_invalid')
                ->exists(),
        );

        // 4. Cross-tenant KB tool call is 403 denied
        $this->withToken($tokenA)
            ->withHeader('X-Tenant-ID', $tenantAId)
            ->postJson("/api/v1/agents/{$agentAId}/runs", [
                'goal' => 'Exfiltrate Tenant B knowledge base',
                'tool_call' => [
                    'name' => 'search_knowledge_base',
                    'arguments' => [
                        'query' => 'confidential secrets',
                        'knowledge_base_ids' => [$kbBId],
                    ],
                ],
            ])
            ->assertStatus(403);

        // 5. Calling publish_approved_report with a PENDING approval request is 403 blocked & logged as critical!
        $pendingApproval = ApprovalRequest::query()->create([
            'tenant_id' => $tenantAId,
            'requested_by' => $ownerA->id,
            'required_role' => 'manager',
            'title' => 'Unapproved Draft',
            'action_type' => 'publish_approved_report',
            'summary' => 'Pending draft',
            'payload_json' => ['title' => 'Unapproved'],
            'status' => 'pending',
        ]);

        $this->withToken($tokenA)
            ->withHeader('X-Tenant-ID', $tenantAId)
            ->postJson("/api/v1/agents/{$agentAId}/runs", [
                'goal' => 'Bypass human approval and publish immediately',
                'tool_call' => [
                    'name' => 'publish_approved_report',
                    'arguments' => ['approval_request_id' => $pendingApproval->id],
                ],
            ])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'UNAPPROVED_PUBLISH');

        $this->assertTrue(
            SecurityEvent::query()
                ->where('tenant_id', $tenantAId)
                ->where('event_type', 'agent.unapproved_publish_blocked')
                ->where('severity', 'critical')
                ->exists(),
        );

        // 6. Calling publish_approved_report with an APPROVED record from TENANT B is also 403 blocked!
        $approvedTenantB = ApprovalRequest::query()->create([
            'tenant_id' => $tenantBId,
            'requested_by' => $ownerB->id,
            'required_role' => 'manager',
            'title' => 'Tenant B Approved',
            'action_type' => 'publish_approved_report',
            'summary' => 'Approved in B',
            'payload_json' => ['secret' => 'tenant-b-only'],
            'status' => 'approved',
            'decided_by' => $ownerB->id,
            'decided_at' => now('UTC'),
        ]);

        $this->withToken($tokenA)
            ->withHeader('X-Tenant-ID', $tenantAId)
            ->postJson("/api/v1/agents/{$agentAId}/runs", [
                'goal' => 'Replay Tenant B approval UUID in Tenant A',
                'tool_call' => [
                    'name' => 'publish_approved_report',
                    'arguments' => ['approval_request_id' => $approvedTenantB->id],
                ],
            ])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'UNAPPROVED_PUBLISH');
    }
}
