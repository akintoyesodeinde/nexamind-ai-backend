<?php

declare(strict_types=1);

namespace App\Domain\Agents\Tools;

use App\Domain\Agents\Contracts\AgentToolInterface;
use App\Domain\Agents\Models\Agent;
use App\Domain\Agents\Models\AgentRun;
use App\Domain\Identity\Models\User;
use App\Domain\Retrieval\Services\PromptInjectionGuard;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Support\Str;

final class ExtractDocumentClausesTool implements AgentToolInterface
{
    public function __construct(
        private readonly PromptInjectionGuard $injectionGuard,
    ) {}

    public function name(): string
    {
        return 'extract_document_clauses';
    }

    public function description(): string
    {
        return 'Extract structured policy obligations, limits, and governance clauses from authorized retrieved document chunks.';
    }

    public function requiresApproval(): bool
    {
        return false;
    }

    public function riskLevel(): string
    {
        return 'low';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['topic', 'chunks'],
            'properties' => [
                'topic' => [
                    'type' => 'string',
                    'minLength' => 2,
                    'maxLength' => 300,
                ],
                'chunks' => [
                    'type' => 'array',
                    'items' => ['type' => 'object'],
                    'maxItems' => 15,
                ],
            ],
        ];
    }

    public function execute(
        Tenant $tenant,
        User $user,
        Agent $agent,
        AgentRun $run,
        array $arguments,
    ): array {
        $topic = trim((string) $arguments['topic']);
        /** @var list<array<string, mixed>> $chunks */
        $chunks = is_array($arguments['chunks']) ? $arguments['chunks'] : [];

        $clauses = [];
        foreach ($chunks as $idx => $chunk) {
            $rawContent = (string) ($chunk['content'] ?? '');
            $sanitized = $this->injectionGuard->sanitizeChunkText(
                text: $rawContent,
                tenantId: $tenant->id,
                actorUserId: $user->id,
                documentId: (string) ($chunk['document_id'] ?? 'unknown'),
                chunkId: (string) ($chunk['chunk_id'] ?? "chunk-{$idx}"),
            );

            $sentences = preg_split('/(?<=[.!?])\s+/', trim($sanitized)) ?: [];
            $primarySentence = trim((string) ($sentences[0] ?? $sanitized));

            $lower = strtolower($primarySentence);
            $category = match (true) {
                str_contains($lower, 'approve') || str_contains($lower, 'approval') => 'approval_control',
                str_contains($lower, 'retain') || str_contains($lower, 'audit') || str_contains($lower, 'log') => 'audit_retention',
                str_contains($lower, 'confidential') || str_contains($lower, 'restrict') || str_contains($lower, 'encrypt') => 'security_access',
                default => 'policy_obligation',
            };

            $clauses[] = [
                'clause_index' => $idx + 1,
                'chunk_id' => (string) ($chunk['chunk_id'] ?? ''),
                'document_id' => (string) ($chunk['document_id'] ?? ''),
                'document_name' => (string) ($chunk['filename'] ?? 'Document'),
                'page_number' => isset($chunk['page_number']) ? (int) $chunk['page_number'] : null,
                'category' => $category,
                'statement' => Str::limit($primarySentence, 320),
                'similarity_score' => round((float) ($chunk['score'] ?? 0.0), 4),
            ];
        }

        return [
            'output' => [
                'topic' => $topic,
                'clauses_extracted' => count($clauses),
                'clauses' => $clauses,
            ],
            'tokens_used' => 0,
            'cost_usd' => '0.000000',
        ];
    }
}
