<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\AI\Contracts\EmbeddingProviderInterface;
use App\Domain\Identity\Models\User;
use App\Domain\Retrieval\Adapters\SqlServerJsonVectorStore;
use App\Domain\Tenancy\Services\TenantService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use ZipArchive;

final class DocumentIngestionAndRetrievalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_full_kb_upload_azurite_sas_versioning_and_native_sql_vector_search(): void
    {
        /** @var TenantService $tenantService */
        $tenantService = app(TenantService::class);

        $owner = User::factory()->create(['email' => 'kb-owner@acme.test']);
        $org = $tenantService->createTenant($owner, 'Knowledge Corp', 'knowledge-corp');
        $tenantId = $org['tenant']->id;
        $token = $owner->createToken('kb-test')->plainTextToken;

        // 1. Create a tenant-visible Knowledge Base
        $kbResp = $this->withToken($token)
            ->withHeader('X-Tenant-ID', $tenantId)
            ->postJson('/api/v1/knowledge-bases', [
                'name' => 'Corporate Policies',
                'description' => 'Approved credit, compliance, and HR operating procedures.',
                'visibility' => 'tenant',
            ])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'corporate-policies');

        $kbId = (string) $kbResp->json('data.id');

        // 2. Upload Loan Policy (.md) -> Azurite + sync IngestDocumentJob + SQL Server 2025 VECTOR(1536)
        $loanContent = "# Section 1 Loan Approval Policy\n\nCommercial loans exceeding 250000 USD require Credit Committee approval and dual executive signatures.\n\n# Section 2 Collateral Rules\n\nIndependent appraisal is mandatory prior to disbursement.";
        $loanFile = UploadedFile::fake()->createWithContent('loan-approval-policy.md', $loanContent);

        $upload1 = $this->withToken($token)
            ->withHeader('X-Tenant-ID', $tenantId)
            ->post('/api/v1/documents', [
                'knowledge_base_id' => $kbId,
                'file' => $loanFile,
                'access_policy' => 'inherit',
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.document.status', 'ready')
            ->assertJsonPath('data.document.current_version', 1);

        $loanDocId = (string) $upload1->json('data.document.id');
        $this->assertGreaterThanOrEqual(2, (int) $upload1->json('data.document.chunk_count'));

        // 3. Upload HR Vacation Policy (.docx real ZIP archive)
        $docxBytes = $this->buildValidDocxBytes('Annual leave policy grants twenty paid vacation days per calendar year for full time staff.');
        $hrFile = UploadedFile::fake()->createWithContent('hr-leave-policy.docx', $docxBytes);

        $upload2 = $this->withToken($token)
            ->withHeader('X-Tenant-ID', $tenantId)
            ->post('/api/v1/documents', [
                'knowledge_base_id' => $kbId,
                'file' => $hrFile,
                'access_policy' => 'inherit',
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.document.status', 'ready');

        $hrDocId = (string) $upload2->json('data.document.id');

        // 4. Verify live Azurite Service SAS temporary download URL works AND unsigned direct URL fails
        $dlResp = $this->withToken($token)
            ->withHeader('X-Tenant-ID', $tenantId)
            ->getJson("/api/v1/documents/{$loanDocId}/download-url")
            ->assertOk()
            ->assertJsonPath('data.filename', 'loan-approval-policy.md');

        $publicSasUrl = (string) $dlResp->json('data.download_url');
        $this->assertStringContainsString('sig=', $publicSasUrl);

        // Translate localhost public endpoint to internal Docker Azurite hostname to verify live SAS fetch
        $internalSasUrl = str_replace('http://127.0.0.1:10000', 'http://azurite:10000', $publicSasUrl);
        $sasFetch = Http::timeout(10)->get($internalSasUrl);
        $this->assertTrue($sasFetch->successful(), 'Azurite SAS download failed: '.$sasFetch->status());
        $this->assertStringContainsString('Credit Committee approval', $sasFetch->body());

        // Unsigned direct blob URL MUST be rejected (private container)
        $unsignedUrl = explode('?', $internalSasUrl, 2)[0];
        $unsignedFetch = Http::timeout(10)->get($unsignedUrl);
        $this->assertFalse($unsignedFetch->successful());

        // 5. Upload Version 2 of Loan Policy and verify version history
        $loanV2 = UploadedFile::fake()->createWithContent(
            'loan-approval-policy.md',
            "# Section 1 Loan Approval Policy v2\n\nCommercial loans exceeding 300000 USD require Credit Committee approval and Chief Risk Officer sign-off.",
        );

        $this->withToken($token)
            ->withHeader('X-Tenant-ID', $tenantId)
            ->post('/api/v1/documents', [
                'knowledge_base_id' => $kbId,
                'document_id' => $loanDocId,
                'file' => $loanV2,
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.document.current_version', 2)
            ->assertJsonPath('data.document.status', 'ready');

        $this->withToken($token)
            ->withHeader('X-Tenant-ID', $tenantId)
            ->getJson("/api/v1/documents/{$loanDocId}/versions")
            ->assertOk()
            ->assertJsonCount(2, 'data');

        // 6. Native SQL Server 2025 VECTOR(1536) semantic search ranks Loan Policy #1
        $searchResp = $this->withToken($token)
            ->withHeader('X-Tenant-ID', $tenantId)
            ->postJson('/api/v1/retrieval/search', [
                'query' => 'What are the commercial loan approval requirements and Chief Risk Officer sign-off?',
                'knowledge_base_ids' => [$kbId],
                'top_k' => 5,
            ])
            ->assertOk()
            ->assertJsonPath('data.adapter', 'sqlsrv_native_vector')
            ->assertJsonPath('data.authorized_document_count', 2);

        $results = $searchResp->json('data.results');
        $this->assertNotEmpty($results);
        $this->assertSame(strtolower($loanDocId), strtolower((string) $results[0]['document_id']));
        $this->assertStringContainsString('Chief Risk Officer', (string) $results[0]['content']);

        // 7. Verify portable JSON fallback adapter returns identical top document
        /** @var SqlServerJsonVectorStore $jsonStore */
        $jsonStore = app(SqlServerJsonVectorStore::class);
        $fallbackHits = $jsonStore->searchAuthorizedChunks(
            tenantId: $tenantId,
            queryEmbedding: app(EmbeddingProviderInterface::class)
                ->embedBatch('default-embedding', ['commercial loan approval Chief Risk Officer'])[0],
            authorizedKnowledgeBaseIds: [$kbId],
            authorizedDocumentIds: [$loanDocId, $hrDocId],
            topK: 3,
        );
        $this->assertNotEmpty($fallbackHits);
        $this->assertSame(strtolower($loanDocId), strtolower($fallbackHits[0]['document_id']));

        // 8. Verify live tenant stats reflect KB and document counts
        $this->withToken($token)
            ->withHeader('X-Tenant-ID', $tenantId)
            ->getJson('/api/v1/tenants/current')
            ->assertOk()
            ->assertJsonPath('data.stats.knowledge_bases_count', 1)
            ->assertJsonPath('data.stats.documents_count', 2);

        // 9. Delete HR document and verify status/chunk cleanup
        $this->withToken($token)
            ->withHeader('X-Tenant-ID', $tenantId)
            ->deleteJson("/api/v1/documents/{$hrDocId}")
            ->assertNoContent();

        $this->withToken($token)
            ->withHeader('X-Tenant-ID', $tenantId)
            ->getJson("/api/v1/documents/{$hrDocId}")
            ->assertNotFound();
    }

    private function buildValidDocxBytes(string $paragraphText): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'test_docx_');
        $zip = new ZipArchive;
        $zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"></Types>');
        $zip->addFromString(
            'word/document.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            .'<w:body><w:p><w:r><w:t>'.htmlspecialchars($paragraphText, ENT_XML1).'</w:t></w:r></w:p></w:body>'
            .'</w:document>',
        );
        $zip->close();
        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $bytes;
    }
}
