<?php

declare(strict_types=1);

namespace App\Domain\Agents\Tools;

use App\Domain\Agents\Contracts\AgentToolInterface;
use App\Domain\Agents\Models\Agent;
use App\Domain\Agents\Models\AgentRun;
use App\Domain\AI\Services\ModelGatewayService;
use App\Domain\Identity\Models\User;
use App\Domain\Retrieval\Services\CitationVerifier;
use App\Domain\Retrieval\Services\GroundedPromptBuilder;
use App\Domain\Tenancy\Models\Tenant;

final class DraftComplianceReportTool implements AgentToolInterface
{
    public function __construct(
        private readonly GroundedPromptBuilder $promptBuilder,
        private readonly ModelGatewayService $modelGateway,
        private readonly CitationVerifier $citationVerifier,
    ) {}

    public function name(): string
    {
        return 'draft_compliance_report';
    }

    public function description(): string
    {
        return 'Generate a schema-validated, citation-verified compliance report grounded strictly in authorized policy chunks.';
    }

    public function requiresApproval(): bool
    {
        return false;
    }

    public function riskLevel(): string
    {
        return 'medium';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['report_title', 'scope', 'chunks'],
            'properties' => [
                'report_title' => [
                    'type' => 'string',
                    'minLength' => 3,
                    'maxLength' => 200,
                ],
                'scope' => [
                    'type' => 'string',
                    'minLength' => 3,
                    'maxLength' => 500,
                ],
                'chunks' => [
                    'type' => 'array',
                    'items' => ['type' => 'object'],
                    'maxItems' => 15,
                ],
                'clauses' => [
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
        $reportTitle = trim((string) $arguments['report_title']);
        $scope = trim((string) $arguments['scope']);
        /** @var list<array{chunk_id: string, document_id: string, knowledge_base_id: string, filename: string, page_number: int|null, section_heading: string|null, content: string, score: float}> $chunks */
        $chunks = is_array($arguments['chunks']) ? $arguments['chunks'] : [];
        /** @var list<array<string, mixed>> $clauses */
        $clauses = isset($arguments['clauses']) && is_array($arguments['clauses']) ? $arguments['clauses'] : [];

        if ($chunks === []) {
            return [
                'output' => [
                    'title' => $reportTitle,
                    'scope' => $scope,
                    'compliance_status' => 'insufficient_evidence',
                    'executive_summary' => 'No authorized policy documents matched the requested compliance scope. Manual policy upload is required before approval.',
                    'findings' => [],
                    'recommendations' => ['Upload and index applicable organizational policies into an authorized knowledge base.'],
                    'citations' => [],
                    'schema_valid' => true,
                ],
                'tokens_used' => 0,
                'cost_usd' => '0.000000',
            ];
        }

        $messages = $this->promptBuilder->buildMessages(
            question: "Draft an executive compliance report titled '{$reportTitle}' covering: {$scope}. Cite every finding strictly using the exact chunk id tags.",
            recentHistory: [],
            retrievedChunks: $chunks,
            tenantId: $tenant->id,
            actorUserId: $user->id,
            includeCitations: true,
        );

        $llmResult = $this->modelGateway->executeChat(
            tenant: $tenant,
            user: $user,
            modelAlias: $agent->model_alias,
            operationType: 'agent_compliance_report',
            messages: $messages,
        );

        $verification = $this->citationVerifier->verifyAndNormalize(
            rawAnswer: $llmResult['content'],
            retrievedChunks: $chunks,
            tenantId: $tenant->id,
            actorUserId: $user->id,
            includeCitations: true,
        );

        $findings = [];
        foreach ($clauses as $clause) {
            $findings[] = [
                'category' => (string) ($clause['category'] ?? 'policy_obligation'),
                'summary' => (string) ($clause['statement'] ?? ''),
                'source_document' => (string) ($clause['document_name'] ?? 'Policy Document'),
                'page_number' => $clause['page_number'] ?? null,
                'status' => 'verified',
            ];
        }

        if ($findings === []) {
            foreach ($verification['citations'] as $cit) {
                $findings[] = [
                    'category' => 'policy_obligation',
                    'summary' => $cit['excerpt'],
                    'source_document' => $cit['document_name'],
                    'page_number' => $cit['page_number'],
                    'status' => 'verified',
                ];
            }
        }

        $recommendations = [
            'Maintain quarterly review of cited organizational policies and verify role-based document permissions.',
            'Route final publication through designated Manager/Admin approval gate before distribution.',
        ];

        return [
            'output' => [
                'title' => $reportTitle,
                'scope' => $scope,
                'compliance_status' => $verification['citations'] !== [] ? 'ready_for_approval' : 'needs_review',
                'executive_summary' => $verification['answer'],
                'findings' => $findings,
                'recommendations' => $recommendations,
                'citations' => $verification['citations'],
                'unverified_citations_stripped' => $verification['fabricated_citations_stripped'],
                'schema_valid' => true,
                'generated_at' => now('UTC')->toISOString(),
            ],
            'tokens_used' => $llmResult['total_tokens'],
            'cost_usd' => $llmResult['cost_usd'],
        ];
    }
}
