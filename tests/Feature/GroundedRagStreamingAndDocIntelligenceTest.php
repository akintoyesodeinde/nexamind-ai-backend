<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\AI\Models\AiUsageRecord;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Services\TenantService;
use Database\Seeders\AiModelSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

final class GroundedRagStreamingAndDocIntelligenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RbacSeeder::class, AiModelSeeder::class]);
    }

    public function test_grounded_rag_citations_abstention_sse_streaming_and_document_intelligence(): void
    {
        /** @var TenantService $tenantService */
        $tenantService = app(TenantService::class);

        $owner = User::factory()->create(['email' => 'rag-owner@acme.test']);
        $org = $tenantService->createTenant($owner, 'Grounded AI Corp', 'grounded-ai-corp');
        $tenantId = $org['tenant']->id;
        $token = $owner->createToken('rag')->plainTextToken;

        // 1. Verify seeded AI models endpoint
        $this->withToken($token)
            ->withHeader('X-Tenant-ID', $tenantId)
            ->getJson('/api/v1/ai/models?capability=chat')
            ->assertOk()
            ->assertJsonFragment(['alias' => 'default-chat']);

        // 2. Create KB & ingest two policy documents
        $kbId = (string) $this->withToken($token)
            ->withHeader('X-Tenant-ID', $tenantId)
            ->postJson('/api/v1/knowledge-bases', ['name' => 'Credit & HR', 'visibility' => 'tenant'])
            ->assertCreated()
            ->json('data.id');

        $loanDocId = (string) $this->withToken($token)
            ->withHeader('X-Tenant-ID', $tenantId)
            ->post('/api/v1/documents', [
                'knowledge_base_id' => $kbId,
                'file' => UploadedFile::fake()->createWithContent(
                    'loan-policy.md',
                    "# Section 1 Commercial Lending\n\nCommercial loans above 250000 USD require Credit Committee approval and Chief Risk Officer sign-off within five business days.",
                ),
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->json('data.document.id');

        $hrDocId = (string) $this->withToken($token)
            ->withHeader('X-Tenant-ID', $tenantId)
            ->post('/api/v1/documents', [
                'knowledge_base_id' => $kbId,
                'file' => UploadedFile::fake()->createWithContent(
                    'hr-handbook.md',
                    "# Section 1 Annual Leave\n\nFull time employees receive twenty paid vacation days each calendar year with manager approval.",
                ),
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->json('data.document.id');

        // 3. Create conversation & ask grounded RAG question
        $convId = (string) $this->withToken($token)
            ->withHeader('X-Tenant-ID', $tenantId)
            ->postJson('/api/v1/conversations', [
                'knowledge_base_ids' => [$kbId],
                'model' => 'default-chat',
            ])
            ->assertCreated()
            ->json('data.id');

        $ragResp = $this->withToken($token)
            ->withHeader('X-Tenant-ID', $tenantId)
            ->postJson("/api/v1/conversations/{$convId}/messages", [
                'knowledge_base_ids' => [$kbId],
                'message' => 'What are our commercial loan approval policies and Chief Risk Officer requirements?',
                'model' => 'default-chat',
                'options' => ['temperature' => 0.2, 'include_citations' => true],
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.abstained', false);

        $citations = $ragResp->json('data.citations');
        $this->assertNotEmpty($citations);
        $this->assertSame(strtolower($loanDocId), strtolower((string) $citations[0]['document_id']));
        $this->assertTrue((bool) $citations[0]['verified']);
        $this->assertStringContainsString('[1]', (string) $ragResp->json('data.answer'));
        $this->assertGreaterThan(0, (int) $ragResp->json('data.usage.input_tokens'));

        $messageId = (string) $ragResp->json('data.message_id');

        // 4. Submit message feedback
        $this->withToken($token)
            ->withHeader('X-Tenant-ID', $tenantId)
            ->postJson("/api/v1/messages/{$messageId}/feedback", [
                'feedback' => 'positive',
                'note' => 'Accurate citation to page 1',
            ])
            ->assertOk()
            ->assertJsonPath('data.feedback', 'positive');

        // 5. Safe Abstention when query has zero supporting evidence in authorized docs
        $this->withToken($token)
            ->withHeader('X-Tenant-ID', $tenantId)
            ->postJson("/api/v1/conversations/{$convId}/messages", [
                'knowledge_base_ids' => [$kbId],
                'message' => 'quantum neutrino propulsion reactor coolant schematics',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'abstained')
            ->assertJsonPath('data.abstained', true)
            ->assertJsonCount(0, 'data.citations');

        // 6. Real Server-Sent Events (SSE) streaming endpoint emits typed lifecycle events
        $sseResp = $this->withToken($token)
            ->withHeader('X-Tenant-ID', $tenantId)
            ->get('/api/v1/conversations/'.$convId.'/stream?'.http_build_query([
                'message' => 'How many vacation days do employees receive under annual leave?',
                'knowledge_base_ids' => [$kbId],
                'model' => 'default-chat',
            ]));

        $sseResp->assertOk();
        $this->assertStringContainsString('text/event-stream', (string) $sseResp->headers->get('Content-Type'));
        $streamed = $sseResp->streamedContent();
        $this->assertStringContainsString('event: message.started', $streamed);
        $this->assertStringContainsString('event: message.delta', $streamed);
        $this->assertStringContainsString('event: message.completed', $streamed);
        $this->assertStringContainsString('hr-handbook.md', $streamed);

        // 7. Document Intelligence AI actions: summarize, classify, extract_structured, compare
        $this->withToken($token)
            ->withHeader('X-Tenant-ID', $tenantId)
            ->postJson("/api/v1/documents/{$loanDocId}/analyze", ['action' => 'summarize'])
            ->assertOk()
            ->assertJsonStructure(['data' => ['result' => ['executive_summary', 'key_points'], 'usage', 'cost_usd']]);

        $this->withToken($token)
            ->withHeader('X-Tenant-ID', $tenantId)
            ->postJson("/api/v1/documents/{$loanDocId}/analyze", ['action' => 'classify'])
            ->assertOk()
            ->assertJsonPath('data.result.primary_category', 'Financial & Credit Policy');

        $this->withToken($token)
            ->withHeader('X-Tenant-ID', $tenantId)
            ->postJson("/api/v1/documents/{$loanDocId}/analyze", [
                'action' => 'extract_structured',
                'schema_fields' => ['thresholds', 'approvers'],
            ])
            ->assertOk()
            ->assertJsonPath('data.result.source_grounded', true);

        $this->withToken($token)
            ->withHeader('X-Tenant-ID', $tenantId)
            ->postJson('/api/v1/documents/compare', [
                'document_a_id' => $loanDocId,
                'document_b_id' => $hrDocId,
            ])
            ->assertOk()
            ->assertJsonPath('data.document_a.filename', 'loan-policy.md')
            ->assertJsonPath('data.document_b.filename', 'hr-handbook.md');

        // 8. AI usage accounting persisted in SQL Server
        $this->assertGreaterThanOrEqual(
            6,
            AiUsageRecord::withoutGlobalScopes()->where('tenant_id', $tenantId)->count(),
        );
    }
}
