<?php

declare(strict_types=1);

namespace App\Domain\Documents\Services;

use App\Domain\AI\Contracts\EmbeddingProviderInterface;
use App\Domain\Audit\Contracts\AuditLoggerInterface;
use App\Domain\Documents\Contracts\DocumentStorageInterface;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentChunk;
use App\Domain\Documents\Models\DocumentPermission;
use App\Domain\Documents\Models\DocumentVersion;
use App\Domain\Identity\Models\User;
use App\Domain\Knowledge\Models\KnowledgeBase;
use App\Domain\Knowledge\Services\AuthorizedScopeResolver;
use App\Domain\Retrieval\Contracts\VectorStoreInterface;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantSetting;
use App\Jobs\IngestDocumentJob;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class DocumentService
{
    public function __construct(
        private readonly UploadSecurityValidator $uploadValidator,
        private readonly DocumentStorageInterface $storage,
        private readonly DocumentTextExtractor $textExtractor,
        private readonly SemanticChunker $chunker,
        private readonly EmbeddingProviderInterface $embeddingProvider,
        private readonly VectorStoreInterface $vectorStore,
        private readonly AuthorizedScopeResolver $scopeResolver,
        private readonly AuditLoggerInterface $auditLogger,
    ) {}

    /**
     * @return array{document: Document, version: DocumentVersion}
     */
    public function upload(
        Tenant $tenant,
        User $actor,
        string $actorRoleSlug,
        KnowledgeBase $knowledgeBase,
        UploadedFile $file,
        string $accessPolicy = 'inherit',
        ?Document $existingDocument = null,
    ): array {
        if (! $this->scopeResolver->canWriteKnowledgeBase($tenant->id, $actor->id, $knowledgeBase->id, $actorRoleSlug)) {
            $this->auditLogger->recordSecurityEvent(
                eventType: 'documents.upload_kb_denied',
                severity: 'medium',
                summary: 'User attempted upload into unauthorized knowledge base',
                details: ['knowledge_base_id' => $knowledgeBase->id],
                tenantId: $tenant->id,
                actorUserId: $actor->id,
            );
            abort(403, 'You do not have write permission on this knowledge base.');
        }

        /** @var TenantSetting $settings */
        $settings = TenantSetting::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->firstOrFail();

        $validated = $this->uploadValidator->validateAndRead($file, $settings, $tenant->id, $actor->id);

        $documentId = $existingDocument !== null ? $existingDocument->id : (string) Str::uuid();
        $nextVersion = $existingDocument !== null ? ($existingDocument->current_version + 1) : 1;

        $storagePath = sprintf(
            'tenants/%s/kb/%s/docs/%s/v%d/%s_%s',
            strtolower($tenant->id),
            strtolower($knowledgeBase->id),
            strtolower($documentId),
            $nextVersion,
            substr($validated['checksum_sha256'], 0, 12),
            $validated['sanitized_filename'],
        );

        $this->storage->put($storagePath, $validated['contents'], $validated['mime_type']);

        /** @var array{document: Document, version: DocumentVersion} $persisted */
        $persisted = DB::transaction(function () use (
            $tenant,
            $actor,
            $knowledgeBase,
            $validated,
            $storagePath,
            $documentId,
            $nextVersion,
            $accessPolicy,
            $existingDocument,
        ): array {
            if ($existingDocument !== null) {
                $existingDocument->fill([
                    'filename' => $validated['sanitized_filename'],
                    'mime_type' => $validated['mime_type'],
                    'file_size_bytes' => $validated['size_bytes'],
                    'storage_path' => $storagePath,
                    'checksum_sha256' => $validated['checksum_sha256'],
                    'current_version' => $nextVersion,
                    'status' => 'pending',
                    'error_message' => null,
                ]);
                $existingDocument->save();
                $document = $existingDocument->refresh();
            } else {
                $document = new Document([
                    'tenant_id' => $tenant->id,
                    'knowledge_base_id' => $knowledgeBase->id,
                    'uploaded_by' => $actor->id,
                    'filename' => $validated['sanitized_filename'],
                    'mime_type' => $validated['mime_type'],
                    'file_size_bytes' => $validated['size_bytes'],
                    'storage_path' => $storagePath,
                    'checksum_sha256' => $validated['checksum_sha256'],
                    'current_version' => 1,
                    'access_policy' => $accessPolicy,
                    'status' => 'pending',
                    'chunk_count' => 0,
                    'character_count' => 0,
                ]);
                $document->id = $documentId;
                $document->save();

                if ($accessPolicy === 'restricted') {
                    DocumentPermission::query()->create([
                        'tenant_id' => $tenant->id,
                        'document_id' => $document->id,
                        'principal_type' => 'user',
                        'principal_id' => $actor->id,
                        'permission_level' => 'admin',
                        'granted_by' => $actor->id,
                    ]);
                }
            }

            $version = DocumentVersion::query()->create([
                'tenant_id' => $tenant->id,
                'document_id' => $document->id,
                'version_number' => $nextVersion,
                'filename' => $validated['sanitized_filename'],
                'mime_type' => $validated['mime_type'],
                'file_size_bytes' => $validated['size_bytes'],
                'storage_path' => $storagePath,
                'checksum_sha256' => $validated['checksum_sha256'],
                'uploaded_by' => $actor->id,
            ]);

            return ['document' => $document, 'version' => $version];
        });

        $this->auditLogger->record(
            action: 'documents.uploaded',
            resourceType: 'document',
            resourceId: $persisted['document']->id,
            metadata: [
                'knowledge_base_id' => $knowledgeBase->id,
                'filename' => $validated['sanitized_filename'],
                'version' => $nextVersion,
                'size_bytes' => $validated['size_bytes'],
                'access_policy' => $persisted['document']->access_policy,
            ],
            tenantId: $tenant->id,
            actorUserId: $actor->id,
        );

        IngestDocumentJob::dispatch($tenant->id, $persisted['document']->id, $persisted['version']->id);

        return [
            'document' => $persisted['document']->refresh(),
            'version' => $persisted['version'],
        ];
    }

    public function ingestVersion(Document $document, DocumentVersion $version): void
    {
        $document->forceFill([
            'status' => 'processing',
            'error_message' => null,
        ])->save();

        try {
            $rawBytes = $this->storage->get($version->storage_path);
            $pages = $this->textExtractor->extractPages($rawBytes, $version->mime_type, $version->filename);
            $chunks = $this->chunker->chunkPages($pages);

            if ($chunks === []) {
                throw new \RuntimeException('No extractable text content was found in this document.');
            }

            /** @var TenantSetting|null $settings */
            $settings = TenantSetting::withoutGlobalScopes()
                ->where('tenant_id', $document->tenant_id)
                ->first();
            $embeddingModel = $settings !== null ? $settings->default_embedding_model : 'default-embedding';

            $texts = array_map(static fn (array $c): string => $c['content'], $chunks);
            $embeddings = [];
            foreach (array_chunk($texts, 16) as $batch) {
                $batchVectors = $this->embeddingProvider->embedBatch($embeddingModel, $batch);
                foreach ($batchVectors as $vec) {
                    $embeddings[] = $vec;
                }
            }

            $totalChars = 0;
            DB::transaction(function () use ($document, $version, $chunks, $embeddings, $embeddingModel, &$totalChars): void {
                DocumentChunk::withoutGlobalScopes()
                    ->where('tenant_id', $document->tenant_id)
                    ->where('document_id', $document->id)
                    ->delete();

                foreach ($chunks as $idx => $chunkData) {
                    $totalChars += strlen($chunkData['content']);

                    $chunk = DocumentChunk::withoutGlobalScopes()->create([
                        'tenant_id' => $document->tenant_id,
                        'knowledge_base_id' => $document->knowledge_base_id,
                        'document_id' => $document->id,
                        'document_version_id' => $version->id,
                        'chunk_index' => $chunkData['chunk_index'],
                        'page_number' => $chunkData['page_number'],
                        'section_heading' => $chunkData['section_heading'],
                        'char_start' => $chunkData['char_start'],
                        'char_end' => $chunkData['char_end'],
                        'token_estimate' => $chunkData['token_estimate'],
                        'content' => $chunkData['content'],
                        'content_hash' => $chunkData['content_hash'],
                        'embedding_model' => $this->embeddingProvider->providerKey().':'.$embeddingModel,
                    ]);

                    if (isset($embeddings[$idx])) {
                        $this->vectorStore->storeChunkEmbedding($chunk->id, $embeddings[$idx]);
                    }
                }
            });

            $document->forceFill([
                'status' => 'ready',
                'page_count' => count($pages),
                'chunk_count' => count($chunks),
                'character_count' => $totalChars,
                'error_message' => null,
                'processed_at' => now('UTC'),
            ])->save();

            $this->auditLogger->record(
                action: 'documents.ingestion.completed',
                resourceType: 'document',
                resourceId: $document->id,
                metadata: [
                    'version' => $version->version_number,
                    'page_count' => count($pages),
                    'chunk_count' => count($chunks),
                    'vector_adapter' => $this->vectorStore->adapterName(),
                ],
                tenantId: $document->tenant_id,
                actorUserId: $document->uploaded_by,
            );
        } catch (Throwable $e) {
            $document->forceFill([
                'status' => 'failed',
                'error_message' => Str::limit($e->getMessage(), 950),
            ])->save();

            $this->auditLogger->record(
                action: 'documents.ingestion.failed',
                resourceType: 'document',
                resourceId: $document->id,
                metadata: ['version' => $version->version_number, 'error' => Str::limit($e->getMessage(), 400)],
                tenantId: $document->tenant_id,
                actorUserId: $document->uploaded_by,
            );
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listAuthorized(
        Tenant $tenant,
        User $user,
        ?string $knowledgeBaseId = null,
        ?string $status = null,
        ?string $search = null,
    ): array {
        $requestedKbs = $knowledgeBaseId !== null ? [$knowledgeBaseId] : null;
        $scope = $this->scopeResolver->resolveAuthorizedSearchScope(
            tenantId: $tenant->id,
            userId: $user->id,
            requestedKnowledgeBaseIds: $requestedKbs,
            readyOnly: false,
        );

        if ($knowledgeBaseId !== null && $scope['unauthorized_kb_ids'] !== []) {
            abort(403, 'Access to the requested knowledge base is denied.');
        }

        if ($scope['document_ids'] === []) {
            return [];
        }

        $query = Document::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('id', $scope['document_ids'])
            ->orderByDesc('created_at');

        if ($status !== null && trim($status) !== '') {
            $query->where('status', $status);
        }

        if ($search !== null && trim($search) !== '') {
            $query->where('filename', 'like', '%'.trim($search).'%');
        }

        return $query->get()
            ->map(static fn (Document $doc): array => $doc->toApiArray())
            ->values()
            ->all();
    }

    public function getAuthorizedOrFail(Tenant $tenant, User $user, string $documentId): Document
    {
        /** @var Document|null $document */
        $document = Document::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('id', $documentId)
            ->where('status', '!=', 'deleted')
            ->first();

        if ($document === null) {
            abort(404, 'Document not found.');
        }

        if (! $this->scopeResolver->canReadDocument($tenant->id, $user->id, $document)) {
            $this->auditLogger->recordSecurityEvent(
                eventType: 'documents.acl_denied',
                severity: 'high',
                summary: 'Unauthorized access attempt to restricted document blocked',
                details: ['document_id' => $document->id],
                tenantId: $tenant->id,
                actorUserId: $user->id,
            );
            abort(403, 'You do not have permission to access this document.');
        }

        return $document;
    }

    /**
     * @return array{download_url: string, expires_in_seconds: int, filename: string}
     */
    public function issueDownloadUrl(Tenant $tenant, User $user, Document $document, int $ttlSeconds = 300): array
    {
        $url = $this->storage->temporaryDownloadUrl($document->storage_path, $document->filename, $ttlSeconds);

        $this->auditLogger->record(
            action: 'documents.download_url_issued',
            resourceType: 'document',
            resourceId: $document->id,
            metadata: ['ttl_seconds' => $ttlSeconds],
            tenantId: $tenant->id,
            actorUserId: $user->id,
        );

        return [
            'download_url' => $url,
            'expires_in_seconds' => $ttlSeconds,
            'filename' => $document->filename,
        ];
    }

    /**
     * @param  list<array{principal_type: string, principal_id: string, permission_level: string}>  $grants
     * @return list<array<string, mixed>>
     */
    public function syncPermissions(
        Tenant $tenant,
        User $actor,
        string $actorRoleSlug,
        Document $document,
        string $accessPolicy,
        array $grants,
    ): array {
        if (! $this->scopeResolver->canManageDocument($tenant->id, $actor->id, $document, $actorRoleSlug)) {
            abort(403, 'You do not have permission to manage ACLs on this document.');
        }

        DB::transaction(function () use ($tenant, $actor, $document, $accessPolicy, $grants): void {
            $document->forceFill(['access_policy' => $accessPolicy])->save();

            DocumentPermission::query()
                ->where('tenant_id', $tenant->id)
                ->where('document_id', $document->id)
                ->delete();

            foreach ($grants as $grant) {
                DocumentPermission::query()->create([
                    'tenant_id' => $tenant->id,
                    'document_id' => $document->id,
                    'principal_type' => $grant['principal_type'],
                    'principal_id' => $grant['principal_id'],
                    'permission_level' => $grant['permission_level'],
                    'granted_by' => $actor->id,
                ]);
            }
        });

        $this->auditLogger->record(
            action: 'documents.permissions.updated',
            resourceType: 'document',
            resourceId: $document->id,
            metadata: ['access_policy' => $accessPolicy, 'grants_count' => count($grants)],
            tenantId: $tenant->id,
            actorUserId: $actor->id,
        );

        return $this->listPermissions($tenant, $document);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listPermissions(Tenant $tenant, Document $document): array
    {
        return DocumentPermission::query()
            ->where('tenant_id', $tenant->id)
            ->where('document_id', $document->id)
            ->orderBy('principal_type')
            ->get()
            ->map(static fn (DocumentPermission $p): array => [
                'id' => $p->id,
                'principal_type' => $p->principal_type,
                'principal_id' => $p->principal_id,
                'permission_level' => $p->permission_level,
                'created_at' => $p->created_at->toISOString(),
            ])
            ->values()
            ->all();
    }

    public function deleteDocument(Tenant $tenant, User $actor, string $actorRoleSlug, Document $document): void
    {
        if (! $this->scopeResolver->canManageDocument($tenant->id, $actor->id, $document, $actorRoleSlug)) {
            abort(403, 'You do not have permission to delete this document.');
        }

        $versions = DocumentVersion::query()
            ->where('tenant_id', $tenant->id)
            ->where('document_id', $document->id)
            ->get();

        DB::transaction(function () use ($tenant, $document): void {
            DocumentChunk::query()
                ->where('tenant_id', $tenant->id)
                ->where('document_id', $document->id)
                ->delete();

            $document->forceFill([
                'status' => 'deleted',
                'chunk_count' => 0,
            ])->save();
        });

        foreach ($versions as $ver) {
            try {
                $this->storage->delete($ver->storage_path);
            } catch (Throwable) {
                // Blob cleanup errors should not roll back soft-deletion
            }
        }

        $this->auditLogger->record(
            action: 'documents.deleted',
            resourceType: 'document',
            resourceId: $document->id,
            metadata: ['filename' => $document->filename],
            tenantId: $tenant->id,
            actorUserId: $actor->id,
        );
    }
}
