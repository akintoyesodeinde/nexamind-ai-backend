<?php

declare(strict_types=1);

namespace App\Domain\Retrieval\Adapters;

use App\Domain\Retrieval\Contracts\VectorStoreInterface;
use Illuminate\Support\Facades\DB;

/**
 * Native SQL Server 2025 / Azure SQL VECTOR(n) exact cosine similarity adapter
 * with mandatory pre-retrieval tenant_id + knowledge_base_id + document_id ACL filtering.
 */
final class SqlServerNativeVectorStore implements VectorStoreInterface
{
    public function __construct(
        private readonly int $dimensions = 1536,
    ) {}

    public function adapterName(): string
    {
        return 'sqlsrv_native_vector';
    }

    /**
     * @param  list<float>  $embedding
     */
    public function storeChunkEmbedding(string $chunkId, array $embedding): void
    {
        $json = json_encode($embedding, JSON_THROW_ON_ERROR);

        DB::statement(
            "UPDATE dbo.document_chunks
             SET embedding = CAST(? AS VECTOR({$this->dimensions})),
                 embedding_json = ?
             WHERE id = ?",
            [$json, $json, $chunkId],
        );
    }

    /**
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
    ): array {
        if ($authorizedKnowledgeBaseIds === [] || $authorizedDocumentIds === []) {
            return [];
        }

        $safeTopK = max(1, min($topK, 50));
        $vectorLiteral = json_encode($queryEmbedding, JSON_THROW_ON_ERROR);
        $kbPlaceholders = implode(',', array_fill(0, count($authorizedKnowledgeBaseIds), '?'));
        $docPlaceholders = implode(',', array_fill(0, count($authorizedDocumentIds), '?'));

        $sql = "
            SELECT TOP ({$safeTopK})
                c.id AS chunk_id,
                c.document_id,
                c.knowledge_base_id,
                d.filename,
                c.page_number,
                c.section_heading,
                c.content,
                (1.0 - VECTOR_DISTANCE('cosine', c.embedding, CAST(? AS VECTOR({$this->dimensions})))) AS score
            FROM dbo.document_chunks c
            INNER JOIN dbo.documents d ON d.id = c.document_id
            WHERE c.tenant_id = ?
              AND d.tenant_id = ?
              AND d.status = 'ready'
              AND c.embedding IS NOT NULL
              AND c.knowledge_base_id IN ({$kbPlaceholders})
              AND c.document_id IN ({$docPlaceholders})
            ORDER BY VECTOR_DISTANCE('cosine', c.embedding, CAST(? AS VECTOR({$this->dimensions}))) ASC
        ";

        $bindings = array_merge(
            [$vectorLiteral, $tenantId, $tenantId],
            $authorizedKnowledgeBaseIds,
            $authorizedDocumentIds,
            [$vectorLiteral],
        );

        $rows = DB::select($sql, $bindings);

        $results = [];
        foreach ($rows as $row) {
            $results[] = [
                'chunk_id' => (string) $row->chunk_id,
                'document_id' => (string) $row->document_id,
                'knowledge_base_id' => (string) $row->knowledge_base_id,
                'filename' => (string) $row->filename,
                'page_number' => $row->page_number !== null ? (int) $row->page_number : null,
                'section_heading' => $row->section_heading !== null ? (string) $row->section_heading : null,
                'content' => (string) $row->content,
                'score' => round((float) $row->score, 6),
            ];
        }

        return $results;
    }
}
