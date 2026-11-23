<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Domain\Audit\Models\SecurityEvent;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Services\TenantService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;
use ZipArchive;

final class DocumentAndRetrievalAclSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_cross_tenant_document_download_and_vector_retrieval_are_100_percent_denied(): void
    {
        /** @var TenantService $tenantService */
        $tenantService = app(TenantService::class);

        $ownerA = User::factory()->create(['email' => 'owner-a@alpha.test']);
        $ownerB = User::factory()->create(['email' => 'owner-b@beta.test']);

        $orgA = $tenantService->createTenant($ownerA, 'Alpha Corp', 'alpha-corp');
        $orgB = $tenantService->createTenant($ownerB, 'Beta Corp', 'beta-corp');

        $tokenA = $ownerA->createToken('a')->plainTextToken;
        $tokenB = $ownerB->createToken('b')->plainTextToken;

        // Tenant B creates KB and uploads secret acquisition plan
        $kbB = $this->withToken($tokenB)
            ->withHeader('X-Tenant-ID', $orgB['tenant']->id)
            ->postJson('/api/v1/knowledge-bases', ['name' => 'Board Strategy', 'visibility' => 'tenant'])
            ->assertCreated();
        $kbBId = (string) $kbB->json('data.id');

        $secretDoc = $this->withToken($tokenB)
            ->withHeader('X-Tenant-ID', $orgB['tenant']->id)
            ->post('/api/v1/documents', [
                'knowledge_base_id' => $kbBId,
                'file' => UploadedFile::fake()->createWithContent(
                    'project-apollo-acquisition.txt',
                    'Confidential Project Apollo acquisition valuation is 450 million dollars.',
                ),
            ], ['Accept' => 'application/json'])
            ->assertCreated();
        $docBId = (string) $secretDoc->json('data.document.id');

        // Switch to Tenant A token
        $this->app['auth']->forgetGuards();

        // Tenant A knows Tenant B's exact Document UUID & KB UUID -> ALL access must fail
        $this->withToken($tokenA)
            ->withHeader('X-Tenant-ID', $orgA['tenant']->id)
            ->getJson("/api/v1/documents/{$docBId}")
            ->assertNotFound();

        $this->withToken($tokenA)
            ->withHeader('X-Tenant-ID', $orgA['tenant']->id)
            ->getJson("/api/v1/documents/{$docBId}/download-url")
            ->assertNotFound();

        $this->withToken($tokenA)
            ->withHeader('X-Tenant-ID', $orgA['tenant']->id)
            ->getJson("/api/v1/documents/{$docBId}/chunks")
            ->assertNotFound();

        // Tenant A tries vector retrieval targeting Tenant B's KB UUID -> 403 + security event
        $this->withToken($tokenA)
            ->withHeader('X-Tenant-ID', $orgA['tenant']->id)
            ->postJson('/api/v1/retrieval/search', [
                'query' => 'Project Apollo acquisition valuation',
                'knowledge_base_ids' => [$kbBId],
            ])
            ->assertForbidden();

        // Tenant A runs broad vector search without KB filter -> MUST return 0 chunks from Tenant B
        $this->withToken($tokenA)
            ->withHeader('X-Tenant-ID', $orgA['tenant']->id)
            ->postJson('/api/v1/retrieval/search', [
                'query' => 'Project Apollo acquisition valuation',
            ])
            ->assertOk()
            ->assertJsonCount(0, 'data.results');

        $this->assertTrue(
            SecurityEvent::query()
                ->where('event_type', 'retrieval.unauthorized_kb_requested')
                ->where('actor_user_id', $ownerA->id)
                ->exists(),
        );
    }

    public function test_confidential_restricted_document_blocks_even_organization_owner_before_retrieval(): void
    {
        /** @var TenantService $tenantService */
        $tenantService = app(TenantService::class);

        $owner = User::factory()->create(['email' => 'ceo@secure.test']);
        $hrManager = User::factory()->create(['email' => 'hr-mgr@secure.test']);

        $org = $tenantService->createTenant($owner, 'Secure Corp', 'secure-corp');
        $tenantId = $org['tenant']->id;
        $tenantService->addMember($org['tenant'], $owner, 'owner', $hrManager->email, null, 'manager');

        $ownerToken = $owner->createToken('owner')->plainTextToken;
        $hrToken = $hrManager->createToken('hr')->plainTextToken;

        // Owner creates a tenant-visible HR KB
        $kbId = (string) $this->withToken($ownerToken)
            ->withHeader('X-Tenant-ID', $tenantId)
            ->postJson('/api/v1/knowledge-bases', ['name' => 'HR Vault', 'visibility' => 'tenant'])
            ->assertCreated()
            ->json('data.id');

        // HR Manager uploads a RESTRICTED confidential whistleblower investigation file
        $this->app['auth']->forgetGuards();
        $docId = (string) $this->withToken($hrToken)
            ->withHeader('X-Tenant-ID', $tenantId)
            ->post('/api/v1/documents', [
                'knowledge_base_id' => $kbId,
                'access_policy' => 'restricted',
                'file' => UploadedFile::fake()->createWithContent(
                    'whistleblower-case-99.txt',
                    'Whistleblower Case 99 internal ethics investigation regarding executive travel expenses.',
                ),
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.document.status', 'ready')
            ->json('data.document.id');

        // HR Manager (explicit admin grant on upload) CAN retrieve it
        $this->withToken($hrToken)
            ->withHeader('X-Tenant-ID', $tenantId)
            ->postJson('/api/v1/retrieval/search', [
                'query' => 'Whistleblower Case 99 ethics investigation',
                'knowledge_base_ids' => [$kbId],
            ])
            ->assertOk()
            ->assertJsonCount(1, 'data.results');

        // Switch to Organization Owner -> MUST be blocked from reading AND retrieving restricted doc!
        $this->app['auth']->forgetGuards();

        $this->withToken($ownerToken)
            ->withHeader('X-Tenant-ID', $tenantId)
            ->getJson("/api/v1/documents/{$docId}")
            ->assertForbidden();

        $this->withToken($ownerToken)
            ->withHeader('X-Tenant-ID', $tenantId)
            ->postJson('/api/v1/retrieval/search', [
                'query' => 'Whistleblower Case 99 ethics investigation',
                'knowledge_base_ids' => [$kbId],
            ])
            ->assertOk()
            ->assertJsonPath('data.authorized_document_count', 0)
            ->assertJsonCount(0, 'data.results');

        // HR Manager explicitly grants Owner read permission -> Owner can now retrieve it
        $this->app['auth']->forgetGuards();
        $this->withToken($hrToken)
            ->withHeader('X-Tenant-ID', $tenantId)
            ->putJson("/api/v1/documents/{$docId}/permissions", [
                'access_policy' => 'restricted',
                'grants' => [
                    ['principal_type' => 'user', 'principal_id' => $hrManager->id, 'permission_level' => 'admin'],
                    ['principal_type' => 'user', 'principal_id' => $owner->id, 'permission_level' => 'read'],
                ],
            ])
            ->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withToken($ownerToken)
            ->withHeader('X-Tenant-ID', $tenantId)
            ->postJson('/api/v1/retrieval/search', [
                'query' => 'Whistleblower Case 99 ethics investigation',
                'knowledge_base_ids' => [$kbId],
            ])
            ->assertOk()
            ->assertJsonCount(1, 'data.results');
    }

    public function test_malicious_spoofed_and_unauthorized_uploads_are_rejected_and_logged(): void
    {
        /** @var TenantService $tenantService */
        $tenantService = app(TenantService::class);

        $owner = User::factory()->create(['email' => 'sec-owner@corp.test']);
        $viewer = User::factory()->create(['email' => 'sec-viewer@corp.test']);
        $org = $tenantService->createTenant($owner, 'SecUpload Corp', 'secupload-corp');
        $tenantId = $org['tenant']->id;
        $tenantService->addMember($org['tenant'], $owner, 'owner', $viewer->email, null, 'viewer');

        $ownerToken = $owner->createToken('o')->plainTextToken;
        $viewerToken = $viewer->createToken('v')->plainTextToken;

        $kbId = (string) $this->withToken($ownerToken)
            ->withHeader('X-Tenant-ID', $tenantId)
            ->postJson('/api/v1/knowledge-bases', ['name' => 'General KB', 'visibility' => 'tenant'])
            ->assertCreated()
            ->json('data.id');

        // 1. Windows PE executable spoofed as .pdf
        $this->withToken($ownerToken)
            ->withHeader('X-Tenant-ID', $tenantId)
            ->post('/api/v1/documents', [
                'knowledge_base_id' => $kbId,
                'file' => UploadedFile::fake()->createWithContent('invoice.pdf', "MZ\x90\x00\x03\x00malicious-binary"),
            ], ['Accept' => 'application/json'])
            ->assertStatus(422);

        // 2. PHP script spoofed as .txt
        $this->withToken($ownerToken)
            ->withHeader('X-Tenant-ID', $tenantId)
            ->post('/api/v1/documents', [
                'knowledge_base_id' => $kbId,
                'file' => UploadedFile::fake()->createWithContent('shell.txt', '<?php system("id"); ?>'),
            ], ['Accept' => 'application/json'])
            ->assertStatus(422);

        // 3. Invalid ZIP without word/document.xml spoofed as .docx
        $tmpZip = tempnam(sys_get_temp_dir(), 'bad_docx_');
        $zip = new ZipArchive;
        $zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('evil.txt', 'not a real docx');
        $zip->close();
        $badDocxBytes = (string) file_get_contents($tmpZip);
        @unlink($tmpZip);

        $this->withToken($ownerToken)
            ->withHeader('X-Tenant-ID', $tenantId)
            ->post('/api/v1/documents', [
                'knowledge_base_id' => $kbId,
                'file' => UploadedFile::fake()->createWithContent('fake.docx', $badDocxBytes),
            ], ['Accept' => 'application/json'])
            ->assertStatus(422);

        // Security events MUST be recorded for rejected uploads
        $this->assertGreaterThanOrEqual(
            3,
            SecurityEvent::query()
                ->where('tenant_id', $tenantId)
                ->where('event_type', 'document.upload_rejected_security')
                ->count(),
        );

        // 4. Viewer role cannot upload documents at all (403)
        $this->app['auth']->forgetGuards();
        $this->withToken($viewerToken)
            ->withHeader('X-Tenant-ID', $tenantId)
            ->post('/api/v1/documents', [
                'knowledge_base_id' => $kbId,
                'file' => UploadedFile::fake()->createWithContent('valid.txt', 'hello world'),
            ], ['Accept' => 'application/json'])
            ->assertForbidden();
    }
}
