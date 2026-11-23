<?php

declare(strict_types=1);

namespace App\Domain\Retrieval\Services;

use App\Domain\Audit\Contracts\AuditLoggerInterface;

/**
 * Treats both user prompts and retrieved document chunks as untrusted content.
 * Neutralizes XML fence breakouts and records prompt-injection telemetry.
 */
final class PromptInjectionGuard
{
    /**
     * @var list<string>
     */
    private const INJECTION_PATTERNS = [
        '/ignore\s+(all\s+)?(previous|prior|above)\s+instructions/i',
        '/disregard\s+(the\s+)?system\s+prompt/i',
        '/reveal\s+(your\s+)?(system\s+prompt|all\s+secrets|api\s+keys)/i',
        '/bypass\s+(tenant|acl|permission|security)/i',
        '/override\s+(safety|authorization|access\s+control)/i',
        '/<\s*\/\s*(retrieved_document_chunk|untrusted_document_evidence|document_slot)\s*>/i',
    ];

    public function __construct(
        private readonly AuditLoggerInterface $auditLogger,
    ) {}

    public function sanitizeChunkText(
        string $text,
        string $tenantId,
        string $actorUserId,
        string $documentId,
        string $chunkId,
    ): string {
        $matches = $this->detectPatterns($text);
        if ($matches !== []) {
            $this->auditLogger->recordSecurityEvent(
                eventType: 'rag.prompt_injection_detected',
                severity: 'high',
                summary: 'Indirect prompt injection pattern detected inside retrieved document chunk',
                details: [
                    'source' => 'document_chunk',
                    'document_id' => $documentId,
                    'chunk_id' => $chunkId,
                    'matched_patterns_count' => count($matches),
                ],
                tenantId: $tenantId,
                actorUserId: $actorUserId,
            );
        }

        // Neutralize delimiter breakout attempts so untrusted text can never escape XML fences
        return str_ireplace(
            ['<retrieved_document_chunk', '</retrieved_document_chunk>', '<untrusted_document_evidence', '</untrusted_document_evidence>', '<document_slot', '</document_slot>'],
            ['[tag:retrieved_document_chunk', '[/tag:retrieved_document_chunk]', '[tag:untrusted_document_evidence', '[/tag:untrusted_document_evidence]', '[tag:document_slot', '[/tag:document_slot]'],
            $text,
        );
    }

    public function inspectUserQuery(string $query, string $tenantId, string $actorUserId): bool
    {
        $matches = $this->detectPatterns($query);
        if ($matches !== []) {
            $this->auditLogger->recordSecurityEvent(
                eventType: 'rag.prompt_injection_detected',
                severity: 'medium',
                summary: 'Potential prompt injection pattern detected in user query',
                details: [
                    'source' => 'user_query',
                    'matched_patterns_count' => count($matches),
                ],
                tenantId: $tenantId,
                actorUserId: $actorUserId,
            );

            return true;
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function detectPatterns(string $text): array
    {
        $found = [];
        foreach (self::INJECTION_PATTERNS as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                $found[] = $pattern;
            }
        }

        return $found;
    }
}
