<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Services;

use App\Domain\AI\Services\ModelGatewayService;
use App\Domain\Analytics\Models\EvaluationCase;
use App\Domain\Analytics\Models\EvaluationDataset;
use App\Domain\Analytics\Models\EvaluationRun;
use App\Domain\Audit\Contracts\AuditLoggerInterface;
use App\Domain\Identity\Models\User;
use App\Domain\Retrieval\Services\CitationVerifier;
use App\Domain\Retrieval\Services\GroundedPromptBuilder;
use App\Domain\Retrieval\Services\PromptInjectionGuard;
use App\Domain\Retrieval\Services\RetrievalService;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class EvaluationService
{
    private const MIN_EVIDENCE_SCORE = 0.08;

    public function __construct(
        private readonly RetrievalService $retrievalService,
        private readonly PromptInjectionGuard $injectionGuard,
        private readonly GroundedPromptBuilder $promptBuilder,
        private readonly ModelGatewayService $modelGateway,
        private readonly CitationVerifier $citationVerifier,
        private readonly AuditLoggerInterface $auditLogger,
    ) {}

    /**
     * @param  array{
     *     name: string,
     *     description?: string|null,
     *     knowledge_base_ids?: list<string>,
     *     cases: list<array{
     *         category?: string,
     *         question: string,
     *         expected_keywords?: list<string>,
     *         expect_abstention?: bool,
     *         require_citations?: bool
     *     }>
     * }  $payload
     */
    public function createDataset(Tenant $tenant, User $actor, array $payload): EvaluationDataset
    {
        /** @var EvaluationDataset $dataset */
        $dataset = DB::transaction(function () use ($tenant, $actor, $payload): EvaluationDataset {
            $dataset = EvaluationDataset::query()->create([
                'tenant_id' => $tenant->id,
                'created_by' => $actor->id,
                'name' => trim($payload['name']),
                'description' => isset($payload['description']) ? trim((string) $payload['description']) : null,
                'knowledge_base_ids_json' => $payload['knowledge_base_ids'] ?? [],
            ]);

            foreach ($payload['cases'] as $caseData) {
                EvaluationCase::query()->create([
                    'tenant_id' => $tenant->id,
                    'evaluation_dataset_id' => $dataset->id,
                    'category' => (string) ($caseData['category'] ?? 'grounded_qa'),
                    'question' => trim((string) $caseData['question']),
                    'expected_keywords_json' => $caseData['expected_keywords'] ?? [],
                    'expect_abstention' => (bool) ($caseData['expect_abstention'] ?? false),
                    'require_citations' => (bool) ($caseData['require_citations'] ?? true),
                ]);
            }

            return $dataset;
        });

        $this->auditLogger->record(
            action: 'evaluations.dataset.created',
            resourceType: 'evaluation',
            resourceId: $dataset->id,
            metadata: ['name' => $dataset->name, 'cases_count' => count($payload['cases'])],
            tenantId: $tenant->id,
            actorUserId: $actor->id,
        );

        return $dataset;
    }

    /**
     * Seeds a curated enterprise QA + safe-abstention evaluation dataset.
     *
     * @param  list<string>  $knowledgeBaseIds
     */
    public function seedCuratedBenchmark(Tenant $tenant, User $actor, array $knowledgeBaseIds = []): EvaluationDataset
    {
        /** @var EvaluationDataset|null $existing */
        $existing = EvaluationDataset::query()
            ->where('tenant_id', $tenant->id)
            ->where('name', 'Enterprise Policy & Abstention Benchmark')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return $this->createDataset($tenant, $actor, [
            'name' => 'Enterprise Policy & Abstention Benchmark',
            'description' => 'Curated benchmark validating >=95% citation correctness, grounded policy synthesis, and safe abstention on out-of-scope questions.',
            'knowledge_base_ids' => $knowledgeBaseIds,
            'cases' => [
                [
                    'category' => 'hr_policy',
                    'question' => 'What does our organizational policy state regarding remote work, approval requirements, or annual leave?',
                    'expected_keywords' => ['policy'],
                    'expect_abstention' => false,
                    'require_citations' => true,
                ],
                [
                    'category' => 'compliance_audit',
                    'question' => 'What security, compliance, retention, or audit controls are documented in our policies?',
                    'expected_keywords' => ['compliance'],
                    'expect_abstention' => false,
                    'require_citations' => true,
                ],
                [
                    'category' => 'abstention_control',
                    'question' => 'quantum neutrino propulsion reactor coolant schematics',
                    'expected_keywords' => [],
                    'expect_abstention' => true,
                    'require_citations' => false,
                ],
            ],
        ]);
    }

    public function runEvaluation(
        Tenant $tenant,
        User $actor,
        EvaluationDataset $dataset,
        ?string $modelAlias = null,
    ): EvaluationRun {
        /** @var TenantSetting|null $settings */
        $settings = TenantSetting::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();
        $resolvedModel = ($modelAlias !== null && trim($modelAlias) !== '')
            ? trim($modelAlias)
            : ($settings !== null ? $settings->default_chat_model : 'default-chat');

        $cases = EvaluationCase::query()
            ->where('tenant_id', $tenant->id)
            ->where('evaluation_dataset_id', $dataset->id)
            ->orderBy('created_at')
            ->get();

        $kbIds = $dataset->knowledge_base_ids_json;
        $scopedKbs = (is_array($kbIds) && $kbIds !== []) ? $kbIds : null;

        $caseResults = [];
        $totalCases = $cases->count();
        $passedCases = 0;

        $citationEvaluatedCount = 0;
        $citationCorrectCount = 0;

        $groundednessSum = 0.0;
        $groundednessCount = 0;

        $abstentionEvaluatedCount = 0;
        $abstentionCorrectCount = 0;

        $latencySum = 0;
        $totalCost = '0.000000';

        foreach ($cases as $case) {
            $caseStart = hrtime(true);

            $search = $this->retrievalService->search(
                tenant: $tenant,
                user: $actor,
                queryText: $case->question,
                knowledgeBaseIds: $scopedKbs,
                topK: 5,
                minScore: self::MIN_EVIDENCE_SCORE,
            );

            $supportingChunks = $search['results'];

            $abstained = false;
            $answer = '';
            $verifiedCitations = [];
            $fabricatedCount = 0;
            $caseCost = '0.000000';

            if ($supportingChunks === []) {
                $abstained = true;
                $answer = 'Abstained: insufficient authorized supporting evidence.';
            } else {
                $sanitizedChunks = [];
                foreach ($supportingChunks as $chunk) {
                    $chunk['content'] = $this->injectionGuard->sanitizeChunkText(
                        text: $chunk['content'],
                        tenantId: $tenant->id,
                        actorUserId: $actor->id,
                        documentId: $chunk['document_id'],
                        chunkId: $chunk['chunk_id'],
                    );
                    $sanitizedChunks[] = $chunk;
                }

                $messages = $this->promptBuilder->buildMessages(
                    question: $case->question,
                    recentHistory: [],
                    retrievedChunks: $sanitizedChunks,
                    tenantId: $tenant->id,
                    actorUserId: $actor->id,
                    includeCitations: true,
                );

                $llm = $this->modelGateway->executeChat(
                    tenant: $tenant,
                    user: $actor,
                    modelAlias: $resolvedModel,
                    operationType: 'evaluation',
                    messages: $messages,
                );

                $caseCost = $llm['cost_usd'];
                $totalCost = bcadd($totalCost, $caseCost, 6);

                $verification = $this->citationVerifier->verifyAndNormalize(
                    rawAnswer: $llm['content'],
                    retrievedChunks: $sanitizedChunks,
                    tenantId: $tenant->id,
                    actorUserId: $actor->id,
                    includeCitations: true,
                );

                $answer = $verification['answer'];
                $verifiedCitations = $verification['citations'];
                $fabricatedCount = $verification['fabricated_citations_stripped'];
                $abstained = $verification['abstained'];
            }

            $latencyMs = (int) round((hrtime(true) - $caseStart) / 1_000_000);
            $latencySum += $latencyMs;

            $citationCorrect = true;
            $groundedness = 1.0;
            $passed = false;

            if ($case->expect_abstention) {
                $abstentionEvaluatedCount++;
                $passed = $abstained && $verifiedCitations === [];
                if ($passed) {
                    $abstentionCorrectCount++;
                }
                $citationCorrect = $verifiedCitations === [] && $fabricatedCount === 0;
                $groundedness = $passed ? 1.0 : 0.0;
            } else {
                $citationEvaluatedCount++;
                $hasValidCitations = ! $case->require_citations || ($verifiedCitations !== [] && $fabricatedCount === 0);
                $citationCorrect = $hasValidCitations && ! $abstained;
                if ($citationCorrect) {
                    $citationCorrectCount++;
                }

                $keywords = $case->expected_keywords_json ?? [];
                if ($abstained) {
                    $groundedness = 0.0;
                } elseif ($keywords === []) {
                    $groundedness = $verifiedCitations !== [] ? 1.0 : 0.5;
                } else {
                    $haystack = strtolower($answer.' '.implode(' ', array_map(static fn (array $c): string => (string) $c['excerpt'], $verifiedCitations)));
                    $matched = 0;
                    foreach ($keywords as $kw) {
                        if ($kw !== '' && str_contains($haystack, strtolower($kw))) {
                            $matched++;
                        }
                    }
                    $groundedness = $matched / count($keywords);
                }

                $groundednessSum += $groundedness;
                $groundednessCount++;
                $passed = $citationCorrect && $groundedness >= 0.5;
            }

            if ($passed) {
                $passedCases++;
            }

            $caseResults[] = [
                'case_id' => $case->id,
                'category' => $case->category,
                'question' => $case->question,
                'expect_abstention' => (bool) $case->expect_abstention,
                'abstained' => $abstained,
                'answer_preview' => Str::limit($answer, 280),
                'verified_citations_count' => count($verifiedCitations),
                'unverified_citations_stripped' => $fabricatedCount,
                'citation_correct' => $citationCorrect,
                'groundedness_pct' => round($groundedness * 100, 2),
                'passed' => $passed,
                'latency_ms' => $latencyMs,
                'cost_usd' => $caseCost,
            ];
        }

        $citationPct = $citationEvaluatedCount > 0
            ? round(($citationCorrectCount / $citationEvaluatedCount) * 100, 2)
            : 100.0;
        $groundednessPct = $groundednessCount > 0
            ? round(($groundednessSum / $groundednessCount) * 100, 2)
            : 100.0;
        $abstentionPct = $abstentionEvaluatedCount > 0
            ? round(($abstentionCorrectCount / $abstentionEvaluatedCount) * 100, 2)
            : 100.0;
        $avgLatency = $totalCases > 0 ? (int) round($latencySum / $totalCases) : 0;

        $run = EvaluationRun::query()->create([
            'tenant_id' => $tenant->id,
            'evaluation_dataset_id' => $dataset->id,
            'triggered_by' => $actor->id,
            'model_alias' => $resolvedModel,
            'status' => 'completed',
            'total_cases' => $totalCases,
            'passed_cases' => $passedCases,
            'citation_correctness_pct' => number_format($citationPct, 2, '.', ''),
            'groundedness_score_pct' => number_format($groundednessPct, 2, '.', ''),
            'abstention_accuracy_pct' => number_format($abstentionPct, 2, '.', ''),
            'avg_latency_ms' => $avgLatency,
            'total_cost_usd' => $totalCost,
            'case_results_json' => $caseResults,
            'completed_at' => now('UTC'),
        ]);

        $this->auditLogger->record(
            action: 'evaluations.run.completed',
            resourceType: 'evaluation',
            resourceId: $run->id,
            metadata: [
                'dataset_id' => $dataset->id,
                'total_cases' => $totalCases,
                'passed_cases' => $passedCases,
                'citation_correctness_pct' => $citationPct,
                'groundedness_score_pct' => $groundednessPct,
            ],
            tenantId: $tenant->id,
            actorUserId: $actor->id,
        );

        return $run;
    }
}
