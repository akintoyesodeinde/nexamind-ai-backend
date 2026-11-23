<?php

declare(strict_types=1);

namespace App\Domain\Retrieval\Services;

use App\Domain\Audit\Contracts\AuditLoggerInterface;
use Illuminate\Support\Str;

/**
 * Verifies every citation emitted by an LLM against the exact set of authorized chunks
 * retrieved for this request. Strips any fabricated/unauthorized citation references.
 */
final class CitationVerifier
{
    public function __construct(
        private readonly AuditLoggerInterface $auditLogger,
    ) {}

    /**
     * @param  list<array{
     *     chunk_id: string,
     *     document_id: string,
     *     knowledge_base_id: string,
     *     filename: string,
     *     page_number: int|null,
     *     section_heading: string|null,
     *     content: string,
     *     score: float
     * }>  $retrievedChunks
     * @return array{
     *     answer: string,
     *     abstained: bool,
     *     fabricated_citations_stripped: int,
     *     citations: list<array{
     *         citation_index: int,
     *         document_id: string,
     *         document_name: string,
     *         page_number: int|null,
     *         section_heading: string|null,
     *         chunk_id: string,
     *         excerpt: string,
     *         similarity_score: float,
     *         verified: bool
     *     }>
     * }
     */
    public function verifyAndNormalize(
        string $rawAnswer,
        array $retrievedChunks,
        string $tenantId,
        string $actorUserId,
        bool $includeCitations = true,
    ): array {
        $trimmed = trim($rawAnswer);
        if (stripos($trimmed, 'ABSTAIN:') === 0) {
            return [
                'answer' => trim(substr($trimmed, 8)),
                'abstained' => true,
                'fabricated_citations_stripped' => 0,
                'citations' => [],
            ];
        }

        $chunkMap = [];
        foreach ($retrievedChunks as $chunk) {
            $chunkMap[strtolower($chunk['chunk_id'])] = $chunk;
        }

        $verifiedByKey = [];
        $fabricatedRefs = [];
        $nextIndex = 1;

        // Match bracketed UUIDs or chunk/doc-like reference tokens: [uuid], [chunk-123], [doc-999]
        $normalizedAnswer = (string) preg_replace_callback(
            '/\[([a-zA-Z0-9_-]{6,64})\]/',
            function (array $match) use ($chunkMap, &$verifiedByKey, &$fabricatedRefs, &$nextIndex, $includeCitations): string {
                $rawRef = $match[1];
                $key = strtolower($rawRef);

                if (isset($chunkMap[$key])) {
                    if (! isset($verifiedByKey[$key])) {
                        $chunk = $chunkMap[$key];
                        $verifiedByKey[$key] = [
                            'citation_index' => $nextIndex++,
                            'document_id' => $chunk['document_id'],
                            'document_name' => $chunk['filename'],
                            'page_number' => $chunk['page_number'],
                            'section_heading' => $chunk['section_heading'],
                            'chunk_id' => $chunk['chunk_id'],
                            'excerpt' => Str::limit(trim($chunk['content']), 550, '...'),
                            'similarity_score' => $chunk['score'],
                            'verified' => true,
                        ];
                    }

                    return $includeCitations ? '['.$verifiedByKey[$key]['citation_index'].']' : '';
                }

                // Treat UUIDs or chunk-/doc- style markers NOT in retrieved set as fabricated citations!
                if (Str::isUuid($rawRef) || preg_match('/^(chunk|doc|ref)[_-]/i', $rawRef) === 1) {
                    $fabricatedRefs[] = $rawRef;

                    return '';
                }

                return $match[0];
            },
            $trimmed,
        );

        if ($fabricatedRefs !== []) {
            $this->auditLogger->recordSecurityEvent(
                eventType: 'rag.fabricated_citation_blocked',
                severity: 'high',
                summary: 'Fabricated or unauthorized citation reference stripped from LLM output',
                details: ['stripped_references' => array_values(array_unique($fabricatedRefs))],
                tenantId: $tenantId,
                actorUserId: $actorUserId,
            );
        }

        $normalizedAnswer = preg_replace('/[ \t]{2,}/', ' ', $normalizedAnswer) ?? $normalizedAnswer;
        $normalizedAnswer = trim($normalizedAnswer);

        return [
            'answer' => $normalizedAnswer,
            'abstained' => false,
            'fabricated_citations_stripped' => count($fabricatedRefs),
            'citations' => $includeCitations ? array_values($verifiedByKey) : [],
        ];
    }
}
