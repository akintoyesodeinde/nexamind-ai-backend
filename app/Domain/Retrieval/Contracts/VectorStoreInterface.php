<?php

declare(strict_types=1);

namespace App\Domain\Retrieval\Contracts;

interface VectorStoreInterface
{
    public function adapterName(): string;

    /**
     * Persist vector representation for a chunk row.
     *
     * @param  list<float>  $embedding
     */
    public function storeChunkEmbedding(string $chunkId, array $embedding): void;

    /**
     * Exact pre-filtered tenant + KB + Document ACL vector similarity search.
     *
     * @param  list<float>  $queryEmbedding
     * @param  list<string>  $authorizedKnowledgeBaseIds
     * @param  list<string>  $authorizedDocumentIds
     * @return list<array{
     *     chunk_id: string,
     *     document_id: string,
     *     knowledge_base_id: string,
     *     filename: string,
     *     page_number: int|null,
     *     section_heading: string|null,
     *     content: string,
     *     score: float
     * }>
     */
    public function searchAuthorizedChunks(
        string $tenantId,
        array $queryEmbedding,
        array $authorizedKnowledgeBaseIds,
        array $authorizedDocumentIds,
        int $topK = 8,
    ): array;
}
