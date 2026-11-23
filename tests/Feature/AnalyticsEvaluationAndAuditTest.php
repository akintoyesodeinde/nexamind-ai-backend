<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Services\TenantService;
use Database\Seeders\AiModelSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

final class AnalyticsEvaluationAndAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RbacSeeder::class, AiModelSeeder::class]);
    }

    public function test_curated_evaluation_achieves_at_least_95_pct_citation_correctness_and_analytics_audit_chain_work(): void
    {
        /** @var TenantService $tenantService */
        $tenantService = app(TenantService::class);

        $owner = User::factory()->create(['email' => 'eval-owner@acme.test']);
        $org = $tenantService->createTenant($owner, 'Eval Analytics Org', 'eval-analytics-org');
        $tenantId = $org['tenant']->id;
        $token = $owner->createToken('eval')->plainTextToken;

        // 1. Upload realistic HR & Compliance policies
        $kbId = (string) $this->withToken($token)
            ->withHeader('X-Tenant-ID', $tenantId)
            ->postJson('/api/v1/knowledge-bases', ['name' => 'Enterprise Policies', 'visibility' => 'tenant'])
            ->assertCreated()
            ->json('data.id');

        $this->withToken($token)
            ->withHeader('X-Tenant-ID', $tenantId)
            ->post('/api/v1/documents', [
                'knowledge_base_id' => $kbId,
                'file' => UploadedFile::fake()->createWithContent(
                    'hr-and-compliance.md',
                    "# HR Remote Work & Annual Leave Policy\n\nFull time staff receive twenty paid annual leave vacation days per year and may work remotely up to three days weekly with manager approval.\n\n# Security Compliance & Audit Retention Controls\n\nAll security compliance audit logs and incident records must be retained for seven years with SHA-256 tamper-evident hash chaining.",
                ),
            ], ['Accept' => 'application/json'])
            ->assertCreated();

        // 2. Seed curated benchmark dataset & execute evaluation run
        $datasetId = (string) $this->withToken($token)
            ->withHeader('X-Tenant-ID', $tenantId)
            ->postJson('/api/v1/evaluations/datasets/seed-benchmark', [
                'knowledge_base_ids' => [$kbId],
            ])
            ->assertCreated()
            ->json('data.id');

        $evalResp = $this->withToken($token)
            ->withHeader('X-Tenant-ID', $tenantId)
            ->postJson('/api/v1/evaluations/runs', [
                'dataset_id' => $datasetId,
            ])
            ->assertCreated();

        $citationPct = (float) $evalResp->json('data.citation_correctness_pct');
        $groundedPct = (float) $evalResp->json('data.groundedness_score_pct');
        $abstentionPct = (float) $evalResp->json('data.abstention_accuracy_pct');

        $this->assertGreaterThanOrEqual(95.0, $citationPct);
        $this->assertGreaterThanOrEqual(95.0, $groundedPct);
        $this->assertSame(100.0, $abstentionPct);
        $this->assertTrue((bool) $evalResp->json('data.meets_citation_target'));

        // 3. Verify Artisan CLI evaluation runner executes cleanly
        $this->artisan('nexamind:eval-run', [
            '--tenant' => $tenantId,
            '--dataset' => $datasetId,
        ])->assertExitCode(0);

        // 4. Verify Analytics Overview & Usage Breakdown return real usage + DECIMAL(18,6) cost
        $overview = $this->withToken($token)
            ->withHeader('X-Tenant-ID', $tenantId)
            ->getJson('/api/v1/analytics/overview?days=30')
            ->assertOk();

        $this->assertGreaterThanOrEqual(4, (int) $overview->json('data.ai_usage.total_requests'));
        $this->assertMatchesRegularExpression('/^\d+\.\d{6}$/', (string) $overview->json('data.budget.monthly_cost_usd'));
        $this->assertSame(100.0, (float) $overview->json('data.evaluation.citation_correctness_pct'));

        $this->withToken($token)
            ->withHeader('X-Tenant-ID', $tenantId)
            ->getJson('/api/v1/analytics/usage?days=30')
            ->assertOk()
            ->assertJsonStructure([
                'data' => ['window_days', 'by_model', 'by_operation', 'daily_trend', 'top_consumers'],
            ]);

        // 5. Verify Security Audit Chain Verification & Security Events endpoints
        $this->withToken($token)
            ->withHeader('X-Tenant-ID', $tenantId)
            ->getJson('/api/v1/audit-logs/verify-chain')
            ->assertOk()
            ->assertJsonPath('data.chain_valid', true);

        $this->withToken($token)
            ->withHeader('X-Tenant-ID', $tenantId)
            ->getJson('/api/v1/security-events')
            ->assertOk();
    }
}
