<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Audit\Contracts\AuditLoggerInterface;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Services\TenantService;
use App\Domain\Workflows\Models\OutboxEvent;
use App\Domain\Workflows\Models\WorkflowRun;
use Database\Seeders\AiModelSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class WorkflowApprovalIdempotencyAndPortfolioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RbacSeeder::class, AiModelSeeder::class]);
    }

    public function test_full_portfolio_compliance_workflow_human_approval_and_zero_duplicate_idempotency(): void
    {
        /** @var TenantService $tenantService */
        $tenantService = app(TenantService::class);

        $owner = User::factory()->create(['email' => 'portfolio-owner@acme.test']);
        $org = $tenantService->createTenant($owner, 'Portfolio Enterprise', 'portfolio-enterprise');
        $tenant = $org['tenant'];
        $tenantId = $tenant->id;
        $ownerToken = $owner->createToken('owner')->plainTextToken;

        // Add a Manager and a Member to test role-gated approval enforcement
        $manager = User::factory()->create(['email' => 'portfolio-manager@acme.test']);
        $tenantService->addMember($tenant, $owner, 'owner', $manager->email, $manager->name, 'manager');
        $managerToken = $manager->createToken('manager')->plainTextToken;

        $member = User::factory()->create(['email' => 'portfolio-member@acme.test']);
        $tenantService->addMember($tenant, $owner, 'owner', $member->email, $member->name, 'member');
        $memberToken = $member->createToken('member')->plainTextToken;

        // 1. Create KB & upload HR/Compliance policy document
        $kbId = (string) $this->withToken($ownerToken)
            ->withHeader('X-Tenant-ID', $tenantId)
            ->postJson('/api/v1/knowledge-bases', ['name' => 'Corporate Compliance', 'visibility' => 'tenant'])
            ->assertCreated()
            ->json('data.id');

        $this->withToken($ownerToken)
            ->withHeader('X-Tenant-ID', $tenantId)
            ->post('/api/v1/documents', [
                'knowledge_base_id' => $kbId,
                'file' => UploadedFile::fake()->createWithContent(
                    'compliance-policy.md',
                    "# Enterprise Compliance & Remote Work Policy\n\nAll employees may work remotely up to three days per week with manager approval. Audit logs must be retained for seven years and encrypted at rest.",
                ),
            ], ['Accept' => 'application/json'])
            ->assertCreated();

        // 2. Seed flagship AI Compliance Report Workflow template
        $workflowDefId = (string) $this->withToken($ownerToken)
            ->withHeader('X-Tenant-ID', $tenantId)
            ->postJson('/api/v1/workflows/templates/compliance-report')
            ->assertCreated()
            ->assertJsonPath('data.slug', 'ai-compliance-report-workflow')
            ->json('data.id');

        // 3. Launch Workflow WITH Idempotency-Key and replay identical request -> 0 duplicate runs!
        $launchPayload = [
            'report_title' => 'Q4 Remote Work & Audit Compliance Report',
            'goal' => 'Review remote work and audit log retention compliance policy obligations.',
            'knowledge_base_ids' => [$kbId],
        ];
        $launchIdemKey = 'wf-launch-idem-001';

        $runResp1 = $this->withToken($ownerToken)
            ->withHeader('X-Tenant-ID', $tenantId)
            ->withHeader('Idempotency-Key', $launchIdemKey)
            ->postJson("/api/v1/workflows/{$workflowDefId}/runs", $launchPayload)
            ->assertCreated()
            ->assertJsonPath('data.status', 'awaiting_approval');

        $workflowRunId = (string) $runResp1->json('data.id');
        $approvalId = (string) $runResp1->json('data.approval_request_id');
        $this->assertNotEmpty($approvalId);

        // Replay exact same launch request -> must replay response & create 0 duplicate runs
        $this->withToken($ownerToken)
            ->withHeader('X-Tenant-ID', $tenantId)
            ->withHeader('Idempotency-Key', $launchIdemKey)
            ->postJson("/api/v1/workflows/{$workflowDefId}/runs", $launchPayload)
            ->assertCreated()
            ->assertHeader('X-Idempotency-Replayed', 'true')
            ->assertJsonPath('data.id', $workflowRunId);

        $this->assertSame(1, WorkflowRun::query()->where('tenant_id', $tenantId)->count());

        // Check Tenant Overview shows live pending_approvals_count = 1
        $this->withToken($ownerToken)
            ->withHeader('X-Tenant-ID', $tenantId)
            ->getJson('/api/v1/tenants/current')
            ->assertOk()
            ->assertJsonPath('data.stats.pending_approvals_count', 1);

        // 4. Member (below manager rank) CANNOT approve a manager-gated request -> 403 denied
        $this->app['auth']->forgetGuards();
        $this->withToken($memberToken)
            ->withHeader('X-Tenant-ID', $tenantId)
            ->postJson("/api/v1/approvals/{$approvalId}/approve", ['note' => 'Unauthorized self-approval'])
            ->assertForbidden();

        // 5. Manager approves in Approval Inbox -> publishes compliance artifact & completes workflow
        $this->app['auth']->forgetGuards();
        $approveIdemKey = 'approve-idem-001';

        $approveResp1 = $this->withToken($managerToken)
            ->withHeader('X-Tenant-ID', $tenantId)
            ->withHeader('Idempotency-Key', $approveIdemKey)
            ->postJson("/api/v1/approvals/{$approvalId}/approve", ['note' => 'Verified all policy citations.'])
            ->assertOk()
            ->assertJsonPath('data.approval.status', 'approved')
            ->assertJsonPath('data.already_decided', false)
            ->assertJsonPath('data.published_artifact.published', true);

        $this->assertNotEmpty($approveResp1->json('data.published_artifact.report'));

        // 6. Replay approval with SAME Idempotency-Key -> cached replay
        $this->withToken($managerToken)
            ->withHeader('X-Tenant-ID', $tenantId)
            ->withHeader('Idempotency-Key', $approveIdemKey)
            ->postJson("/api/v1/approvals/{$approvalId}/approve", ['note' => 'Verified all policy citations.'])
            ->assertOk()
            ->assertHeader('X-Idempotency-Replayed', 'true');

        // 7. Retry approval with DIFFERENT Idempotency-Key (simulating network timeout retry) -> 0 duplicate side effects!
        $this->withToken($managerToken)
            ->withHeader('X-Tenant-ID', $tenantId)
            ->withHeader('Idempotency-Key', 'approve-idem-retry-002')
            ->postJson("/api/v1/approvals/{$approvalId}/approve", ['note' => 'Retry click'])
            ->assertOk()
            ->assertJsonPath('data.already_decided', true)
            ->assertJsonPath('data.approval.status', 'approved');

        // Confirm conflicting reject after approval returns 409
        $this->withToken($managerToken)
            ->withHeader('X-Tenant-ID', $tenantId)
            ->postJson("/api/v1/approvals/{$approvalId}/reject", ['note' => 'Too late'])
            ->assertStatus(409);

        // Verify ZERO duplicate outbox events & ZERO duplicate publish steps
        $this->assertSame(
            1,
            OutboxEvent::query()
                ->where('tenant_id', $tenantId)
                ->where('event_type', 'workflow.report.published')
                ->count(),
        );
        $this->assertSame(
            1,
            OutboxEvent::query()
                ->where('tenant_id', $tenantId)
                ->where('event_type', 'approvals.approved')
                ->count(),
        );
        $this->assertSame(
            1,
            DB::table('agent_steps')
                ->where('tenant_id', $tenantId)
                ->where('tool_name', 'publish_approved_report')
                ->count(),
        );

        // Verify workflow run reached completed with published artifact
        $this->withToken($managerToken)
            ->withHeader('X-Tenant-ID', $tenantId)
            ->getJson("/api/v1/workflows/runs/{$workflowRunId}")
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.output.published_artifact.published', true);

        // Verify tamper-evident SHA-256 audit chain integrity
        /** @var AuditLoggerInterface $auditLogger */
        $auditLogger = app(AuditLoggerInterface::class);
        $this->assertTrue($auditLogger->verifyTenantChain($tenantId));

        // Verify cross-tenant approval isolation
        $outsider = User::factory()->create(['email' => 'outsider-wf@globex.test']);
        $orgB = $tenantService->createTenant($outsider, 'Globex', 'globex-wf');
        $outsiderToken = $outsider->createToken('outsider')->plainTextToken;
        $this->app['auth']->forgetGuards();

        $this->withToken($outsiderToken)
            ->withHeader('X-Tenant-ID', $orgB['tenant']->id)
            ->getJson("/api/v1/approvals/{$approvalId}")
            ->assertNotFound();
    }
}
