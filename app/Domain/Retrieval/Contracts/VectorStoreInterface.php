<?php

declare(strict_types=1);

namespace App\Domain\Retrieval\Contracts;

interface VectorStoreInterface
{
    public function adapterName(): string;

    /**
     * @param  list<float>  $queryEmbedding
     * @param  list<string>  $authorizedKnowledgeBaseIds
     * @param  list<string>  $authorizedDocumentIds
     * @return list<array{chunk_id: string, document_id: string, score: float}>
     */
    public function searchAuthorizedChunks(
        string $tenantId,
        array $queryEmbedding,
        array $authorizedKnowledgeBaseIds,
        array $authorizedDocumentIds,
        int $topK = 8,
    ): array;
}
