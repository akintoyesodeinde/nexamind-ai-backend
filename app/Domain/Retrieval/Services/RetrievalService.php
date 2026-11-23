<?php

declare(strict_types=1);

namespace App\Domain\Retrieval\Services;

use App\Domain\AI\Contracts\EmbeddingProviderInterface;
use App\Domain\Audit\Contracts\AuditLoggerInterface;
use App\Domain\Identity\Models\User;
use App\Domain\Knowledge\Services\AuthorizedScopeResolver;
use App\Domain\Retrieval\Contracts\VectorStoreInterface;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantSetting;

final class RetrievalService
{
    public function __construct(
        private readonly AuthorizedScopeResolver $scopeResolver,
        private readonly EmbeddingProviderInterface $embeddingProvider,
        private readonly VectorStoreInterface $vectorStore,
        private readonly AuditLoggerInterface $auditLogger,
    ) {}

    /**
     * Performs strict pre-retrieval tenant + KB + Document ACL vector similarity search.
     *
     * @param  list<string>|null  $knowledgeBaseIds
     * @return array{
     *     adapter: string,
     *     query: string,
     *     authorized_document_count: int,
     *     results: list<array{
     *         chunk_id: string,
     *         document_id: string,
     *         knowledge_base_id: string,
     *         filename: string,
     *         page_number: int|null,
     *         section_heading: string|null,
     *         content: string,
     *         score: float
     *     }>
     * }
     */
    public function search(
        Tenant $tenant,
        User $user,
        string $queryText,
        ?array $knowledgeBaseIds = null,
        int $topK = 8,
        float $minScore = 0.0,
    ): array {
        $scope = $this->scopeResolver->resolveAuthorizedSearchScope(
            tenantId: $tenant->id,
            userId: $user->id,
            requestedKnowledgeBaseIds: $knowledgeBaseIds,
            readyOnly: true,
        );

        if ($scope['unauthorized_kb_ids'] !== []) {
            $this->auditLogger->recordSecurityEvent(
                eventType: 'retrieval.unauthorized_kb_requested',
                severity: 'high',
                summary: 'User requested vector retrieval against unauthorized or cross-tenant knowledge base',
                details: ['unauthorized_kb_ids' => $scope['unauthorized_kb_ids']],
                tenantId: $tenant->id,
                actorUserId: $user->id,
            );
            abort(403, 'Access to one or more requested knowledge bases is denied.');
        }

        if ($scope['document_ids'] === []) {
            return [
                'adapter' => $this->vectorStore->adapterName(),
                'query' => $queryText,
                'authorized_document_count' => 0,
                'results' => [],
            ];
        }

        /** @var TenantSetting|null $settings */
        $settings = TenantSetting::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->first();
        $embeddingModel = $settings !== null ? $settings->default_embedding_model : 'default-embedding';

        $vectors = $this->embeddingProvider->embedBatch($embeddingModel, [$queryText]);
        $queryVector = $vectors[0] ?? [];

        $hits = $this->vectorStore->searchAuthorizedChunks(
            tenantId: $tenant->id,
            queryEmbedding: $queryVector,
            authorizedKnowledgeBaseIds: $scope['knowledge_base_ids'],
            authorizedDocumentIds: $scope['document_ids'],
            topK: $topK,
        );

        $filtered = array_values(array_filter(
            $hits,
            static fn (array $hit): bool => $hit['score'] >= $minScore,
        ));

        return [
            'adapter' => $this->vectorStore->adapterName(),
            'query' => $queryText,
            'authorized_document_count' => count($scope['document_ids']),
            'results' => $filtered,
        ];
    }
}
