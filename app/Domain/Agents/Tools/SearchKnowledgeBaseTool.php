<?php

declare(strict_types=1);

namespace App\Domain\Agents\Tools;

use App\Domain\Agents\Contracts\AgentToolInterface;
use App\Domain\Agents\Exceptions\ToolSecurityException;
use App\Domain\Agents\Models\Agent;
use App\Domain\Agents\Models\AgentRun;
use App\Domain\Audit\Contracts\AuditLoggerInterface;
use App\Domain\Identity\Models\User;
use App\Domain\Retrieval\Services\RetrievalService;
use App\Domain\Tenancy\Models\Tenant;

final class SearchKnowledgeBaseTool implements AgentToolInterface
{
    public function __construct(
        private readonly RetrievalService $retrievalService,
        private readonly AuditLoggerInterface $auditLogger,
    ) {}

    public function name(): string
    {
        return 'search_knowledge_base';
    }

    public function description(): string
    {
        return 'Search authorized organizational knowledge bases using tenant- and ACL-enforced SQL Server vector retrieval.';
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
            'required' => ['query'],
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'minLength' => 2,
                    'maxLength' => 1000,
                ],
                'knowledge_base_ids' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'maxItems' => 20,
                ],
                'top_k' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 12,
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
        $query = trim((string) $arguments['query']);
        $topK = isset($arguments['top_k']) ? (int) $arguments['top_k'] : 6;
        $agentAllowedKbs = array_values(array_map('strtolower', $agent->allowed_kb_ids_json ?? []));

        /** @var list<string>|null $requestedKbs */
        $requestedKbs = isset($arguments['knowledge_base_ids']) && is_array($arguments['knowledge_base_ids'])
            ? array_values(array_map(static fn (mixed $v): string => (string) $v, $arguments['knowledge_base_ids']))
            : null;

        if ($agentAllowedKbs !== [] && $requestedKbs !== null && $requestedKbs !== []) {
            $disallowed = array_values(array_filter(
                $requestedKbs,
                static fn (string $id): bool => ! in_array(strtolower($id), $agentAllowedKbs, true),
            ));
            if ($disallowed !== []) {
                $this->auditLogger->recordSecurityEvent(
                    eventType: 'agent.unauthorized_kb_tool_call',
                    severity: 'high',
                    summary: 'Agent attempted to query knowledge bases outside its allowlist',
                    details: [
                        'agent_id' => $agent->id,
                        'agent_run_id' => $run->id,
                        'disallowed_kb_ids' => $disallowed,
                    ],
                    tenantId: $tenant->id,
                    actorUserId: $user->id,
                );

                throw new ToolSecurityException(
                    errorCode: 'UNAUTHORIZED_KB',
                    message: 'One or more requested knowledge bases are outside this agent allowlist.',
                    httpStatus: 403,
                    details: ['disallowed_kb_ids' => $disallowed],
                );
            }
        }

        $effectiveKbs = ($requestedKbs !== null && $requestedKbs !== [])
            ? $requestedKbs
            : (($agent->allowed_kb_ids_json ?? []) !== [] ? $agent->allowed_kb_ids_json : null);

        $searchResult = $this->retrievalService->search(
            tenant: $tenant,
            user: $user,
            queryText: $query,
            knowledgeBaseIds: $effectiveKbs,
            topK: $topK,
        );

        return [
            'output' => [
                'adapter' => $searchResult['adapter'],
                'query' => $query,
                'authorized_document_count' => $searchResult['authorized_document_count'],
                'hit_count' => count($searchResult['results']),
                'chunks' => $searchResult['results'],
            ],
            'tokens_used' => 0,
            'cost_usd' => '0.000000',
        ];
    }
}
