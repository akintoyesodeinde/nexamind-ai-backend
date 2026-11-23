<?php

declare(strict_types=1);

namespace App\Domain\Retrieval\Adapters;

use App\Domain\Retrieval\Contracts\VectorStoreInterface;
use Illuminate\Support\Facades\DB;

/**
 * Portable fallback vector store for older local SQL Server versions lacking VECTOR(n).
 * Applies identical pre-retrieval SQL tenant + KB + Document ACL filtering before scoring.
 */
final class SqlServerJsonVectorStore implements VectorStoreInterface
{
    public function adapterName(): string
    {
        return 'sqlsrv_json_fallback';
    }

    /**
     * @param  list<float>  $embedding
     */
    public function storeChunkEmbedding(string $chunkId, array $embedding): void
    {
        $json = json_encode($embedding, JSON_THROW_ON_ERROR);

        DB::table('document_chunks')
            ->where('id', $chunkId)
            ->update(['embedding_json' => $json]);
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

        $rows = DB::table('document_chunks as c')
            ->join('documents as d', 'd.id', '=', 'c.document_id')
            ->where('c.tenant_id', $tenantId)
            ->where('d.tenant_id', $tenantId)
            ->where('d.status', 'ready')
            ->whereNotNull('c.embedding_json')
            ->whereIn('c.knowledge_base_id', $authorizedKnowledgeBaseIds)
            ->whereIn('c.document_id', $authorizedDocumentIds)
            ->select([
                'c.id as chunk_id',
                'c.document_id',
                'c.knowledge_base_id',
                'd.filename',
                'c.page_number',
                'c.section_heading',
                'c.content',
                'c.embedding_json',
            ])
            ->get();

        $scored = [];
        foreach ($rows as $row) {
            /** @var list<float>|null $candidate */
            $candidate = json_decode((string) $row->embedding_json, true);
            if (! is_array($candidate) || count($candidate) !== count($queryEmbedding)) {
                continue;
            }

            $scored[] = [
                'chunk_id' => (string) $row->chunk_id,
                'document_id' => (string) $row->document_id,
                'knowledge_base_id' => (string) $row->knowledge_base_id,
                'filename' => (string) $row->filename,
                'page_number' => $row->page_number !== null ? (int) $row->page_number : null,
                'section_heading' => $row->section_heading !== null ? (string) $row->section_heading : null,
                'content' => (string) $row->content,
                'score' => round($this->cosineSimilarity($queryEmbedding, $candidate), 6),
            ];
        }

        usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_slice($scored, 0, $safeTopK);
    }

    /**
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    private function cosineSimilarity(array $a, array $b): float
    {
        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;
        $len = count($a);

        for ($i = 0; $i < $len; $i++) {
            $av = (float) $a[$i];
            $bv = (float) $b[$i];
            $dot += $av * $bv;
            $normA += $av * $av;
            $normB += $bv * $bv;
        }

        $denom = sqrt($normA) * sqrt($normB);

        return $denom > 1e-12 ? ($dot / $denom) : 0.0;
    }
}
