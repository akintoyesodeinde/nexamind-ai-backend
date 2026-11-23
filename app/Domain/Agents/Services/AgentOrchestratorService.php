<?php

declare(strict_types=1);

namespace App\Domain\Agents\Services;

use App\Domain\Agents\Exceptions\ToolSecurityException;
use App\Domain\Agents\Models\Agent;
use App\Domain\Agents\Models\AgentRun;
use App\Domain\Agents\Models\AgentStep;
use App\Domain\Agents\Models\AgentTool;
use App\Domain\Audit\Contracts\AuditLoggerInterface;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class AgentOrchestratorService
{
    public function __construct(
        private readonly ToolRegistry $toolRegistry,
        private readonly AuditLoggerInterface $auditLogger,
    ) {}

    /**
     * @param  array{
     *     name: string,
     *     description?: string|null,
     *     system_prompt: string,
     *     model_alias?: string,
     *     allowed_kb_ids?: list<string>,
     *     max_steps?: int,
     *     max_tokens_per_run?: int,
     *     require_approval?: bool,
     *     tools?: list<string>
     * }  $payload
     */
    public function createAgent(Tenant $tenant, User $actor, array $payload): Agent
    {
        $requestedTools = $payload['tools'] ?? [
            'search_knowledge_base',
            'extract_document_clauses',
            'draft_compliance_report',
            'create_approval_request',
            'publish_approved_report',
        ];

        $this->assertAllowedToolList($tenant, $actor, $requestedTools);

        $slugBase = Str::slug($payload['name']);
        $slug = $slugBase !== '' ? $slugBase : 'agent';
        $suffix = 1;
        while (Agent::query()->where('tenant_id', $tenant->id)->where('slug', $slug)->exists()) {
            $suffix++;
            $slug = Str::limit($slugBase, 100, '').'-'.$suffix;
        }

        /** @var Agent $agent */
        $agent = DB::transaction(function () use ($tenant, $actor, $payload, $slug, $requestedTools): Agent {
            $agent = Agent::query()->create([
                'tenant_id' => $tenant->id,
                'created_by' => $actor->id,
                'name' => trim($payload['name']),
                'slug' => $slug,
                'description' => isset($payload['description']) ? trim((string) $payload['description']) : null,
                'system_prompt' => trim($payload['system_prompt']),
                'model_alias' => $payload['model_alias'] ?? 'default-chat',
                'allowed_kb_ids_json' => $payload['allowed_kb_ids'] ?? [],
                'max_steps' => min(max((int) ($payload['max_steps'] ?? 8), 1), 12),
                'max_tokens_per_run' => min(max((int) ($payload['max_tokens_per_run'] ?? 12000), 500), 100000),
                'require_approval' => (bool) ($payload['require_approval'] ?? true),
                'status' => 'active',
            ]);

            foreach ($this->toolRegistry->allowedToolNames() as $toolName) {
                AgentTool::query()->create([
                    'tenant_id' => $tenant->id,
                    'agent_id' => $agent->id,
                    'tool_name' => $toolName,
                    'is_enabled' => in_array($toolName, $requestedTools, true),
                    'requires_approval' => $toolName === 'publish_approved_report',
                    'config_json' => [],
                ]);
            }

            return $agent;
        });

        $this->auditLogger->record(
            action: 'agents.created',
            resourceType: 'agent',
            resourceId: $agent->id,
            metadata: ['slug' => $agent->slug, 'tools' => $requestedTools],
            tenantId: $tenant->id,
            actorUserId: $actor->id,
        );

        return $agent;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function updateAgent(Tenant $tenant, User $actor, Agent $agent, array $payload): Agent
    {
        if (isset($payload['tools']) && is_array($payload['tools'])) {
            /** @var list<string> $toolsList */
            $toolsList = array_values(array_map(static fn (mixed $v): string => (string) $v, $payload['tools']));
            $this->assertAllowedToolList($tenant, $actor, $toolsList);
        }

        DB::transaction(function () use ($tenant, $agent, $payload): void {
            $updates = [];
            foreach (['name', 'description', 'system_prompt', 'model_alias', 'status'] as $field) {
                if (array_key_exists($field, $payload)) {
                    $updates[$field] = $payload[$field];
                }
            }
            if (array_key_exists('allowed_kb_ids', $payload)) {
                $updates['allowed_kb_ids_json'] = $payload['allowed_kb_ids'];
            }
            if (array_key_exists('max_steps', $payload)) {
                $updates['max_steps'] = min(max((int) $payload['max_steps'], 1), 12);
            }
            if (array_key_exists('max_tokens_per_run', $payload)) {
                $updates['max_tokens_per_run'] = min(max((int) $payload['max_tokens_per_run'], 500), 100000);
            }
            if (array_key_exists('require_approval', $payload)) {
                $updates['require_approval'] = (bool) $payload['require_approval'];
            }

            if ($updates !== []) {
                $agent->fill($updates);
                $agent->save();
            }

            if (isset($payload['tools']) && is_array($payload['tools'])) {
                /** @var list<string> $enabledTools */
                $enabledTools = array_values(array_map(static fn (mixed $v): string => (string) $v, $payload['tools']));
                foreach ($this->toolRegistry->allowedToolNames() as $toolName) {
                    AgentTool::query()->updateOrCreate(
                        ['tenant_id' => $tenant->id, 'agent_id' => $agent->id, 'tool_name' => $toolName],
                        [
                            'is_enabled' => in_array($toolName, $enabledTools, true),
                            'requires_approval' => $toolName === 'publish_approved_report',
                        ],
                    );
                }
            }
        });

        $this->auditLogger->record(
            action: 'agents.updated',
            resourceType: 'agent',
            resourceId: $agent->id,
            metadata: ['updated_fields' => array_keys($payload)],
            tenantId: $tenant->id,
            actorUserId: $actor->id,
        );

        $agent->refresh();

        return $agent;
    }

    /**
     * Executes a controlled multi-step agent run using allowlisted tools and bounded step limits.
     *
     * @param  array<string, mixed>  $input
     */
    public function executeRun(
        Tenant $tenant,
        User $actor,
        Agent $agent,
        string $goal,
        array $input = [],
        ?string $workflowRunId = null,
    ): AgentRun {
        if ($agent->status !== 'active') {
            abort(422, 'Cannot run a disabled agent.');
        }

        $startTime = hrtime(true);
        $run = AgentRun::query()->create([
            'tenant_id' => $tenant->id,
            'agent_id' => $agent->id,
            'triggered_by' => $actor->id,
            'goal' => trim($goal),
            'input_json' => $input,
            'status' => 'running',
            'step_count' => 0,
            'total_tokens' => 0,
            'cost_usd' => '0.000000',
            'started_at' => now('UTC'),
        ]);

        $stepNum = 0;
        $totalTokens = 0;
        $totalCost = '0.000000';
        $maxSteps = min(max((int) $agent->max_steps, 1), 12);

        try {
            // Optional explicit tool invocation mode (strictly validated through ToolRegistry)
            if (isset($input['tool_call']) && is_array($input['tool_call'])) {
                $toolName = (string) ($input['tool_call']['name'] ?? '');
                /** @var array<string, mixed> $toolArgs */
                $toolArgs = is_array($input['tool_call']['arguments'] ?? null) ? $input['tool_call']['arguments'] : [];

                $stepNum++;
                $res = $this->runLoggedStep($tenant, $actor, $agent, $run, $stepNum, 'tool_call', $toolName, $toolArgs);
                $totalTokens += $res['tokens_used'];
                $totalCost = bcadd($totalCost, $res['cost_usd'], 6);

                $run->fill([
                    'status' => $toolName === 'create_approval_request' ? 'awaiting_approval' : 'completed',
                    'step_count' => $stepNum,
                    'total_tokens' => $totalTokens,
                    'cost_usd' => $totalCost,
                    'output_json' => $res['output'],
                    'latency_ms' => (int) round((hrtime(true) - $startTime) / 1_000_000),
                    'completed_at' => $toolName === 'create_approval_request' ? null : now('UTC'),
                ])->save();

                return $run;
            }

            // Standard controlled enterprise compliance pipeline:
            // Step 1: search_knowledge_base (ACL-enforced vector search)
            $searchArgs = [
                'query' => trim($goal),
                'top_k' => min(max((int) ($input['top_k'] ?? 6), 1), 12),
            ];
            if (isset($input['knowledge_base_ids']) && is_array($input['knowledge_base_ids']) && $input['knowledge_base_ids'] !== []) {
                $searchArgs['knowledge_base_ids'] = array_values(array_map(static fn (mixed $v): string => (string) $v, $input['knowledge_base_ids']));
            }

            $stepNum++;
            $searchRes = $this->runLoggedStep($tenant, $actor, $agent, $run, $stepNum, 'retrieval', 'search_knowledge_base', $searchArgs);
            $chunks = is_array($searchRes['output']['chunks'] ?? null) ? $searchRes['output']['chunks'] : [];

            // Step 2: extract_document_clauses (if enabled & within step budget)
            $clauses = [];
            if ($stepNum < $maxSteps && $this->isToolEnabled($tenant->id, $agent->id, 'extract_document_clauses')) {
                $stepNum++;
                $extractRes = $this->runLoggedStep($tenant, $actor, $agent, $run, $stepNum, 'extraction', 'extract_document_clauses', [
                    'topic' => Str::limit(trim($goal), 280, ''),
                    'chunks' => $chunks,
                ]);
                $clauses = is_array($extractRes['output']['clauses'] ?? null) ? $extractRes['output']['clauses'] : [];
            }

            // Step 3: draft_compliance_report (grounded LLM synthesis + CitationVerifier)
            $draftReport = null;
            if ($stepNum < $maxSteps && $this->isToolEnabled($tenant->id, $agent->id, 'draft_compliance_report')) {
                $stepNum++;
                $reportTitle = isset($input['report_title']) && is_string($input['report_title']) && trim($input['report_title']) !== ''
                    ? trim($input['report_title'])
                    : 'AI Compliance Report: '.Str::limit(trim($goal), 120, '');

                $draftRes = $this->runLoggedStep($tenant, $actor, $agent, $run, $stepNum, 'synthesis', 'draft_compliance_report', [
                    'report_title' => $reportTitle,
                    'scope' => Str::limit(trim($goal), 450, ''),
                    'chunks' => $chunks,
                    'clauses' => $clauses,
                ]);
                $totalTokens += $draftRes['tokens_used'];
                $totalCost = bcadd($totalCost, $draftRes['cost_usd'], 6);
                $draftReport = $draftRes['output'];
            }

            // Step 4: Human-in-the-loop Approval Request if required
            $approvalOutput = null;
            $requiresApproval = (bool) $agent->require_approval && ($input['skip_approval'] ?? false) !== true;
            if ($requiresApproval && $stepNum < $maxSteps && $this->isToolEnabled($tenant->id, $agent->id, 'create_approval_request')) {
                $stepNum++;
                $approvalArgs = [
                    'title' => is_array($draftReport) ? (string) ($draftReport['title'] ?? 'Agent Action Approval') : 'Agent Output Approval',
                    'summary' => is_array($draftReport)
                        ? Str::limit((string) ($draftReport['executive_summary'] ?? $goal), 1800, '')
                        : Str::limit($goal, 1800, ''),
                    'action_type' => 'publish_approved_report',
                    'required_role' => isset($input['approver_role']) && is_string($input['approver_role']) ? $input['approver_role'] : 'manager',
                    'payload' => [
                        'goal' => $goal,
                        'report' => $draftReport,
                        'clauses_count' => count($clauses),
                    ],
                ];
                if ($workflowRunId !== null) {
                    $approvalArgs['workflow_run_id'] = $workflowRunId;
                }

                $approvalRes = $this->runLoggedStep($tenant, $actor, $agent, $run, $stepNum, 'approval_gate', 'create_approval_request', $approvalArgs);
                $approvalOutput = $approvalRes['output'];
            }

            $finalStatus = $approvalOutput !== null ? 'awaiting_approval' : 'completed';
            $run->fill([
                'status' => $finalStatus,
                'step_count' => $stepNum,
                'total_tokens' => $totalTokens,
                'cost_usd' => $totalCost,
                'latency_ms' => (int) round((hrtime(true) - $startTime) / 1_000_000),
                'output_json' => [
                    'retrieved_chunks_count' => count($chunks),
                    'clauses_count' => count($clauses),
                    'report' => $draftReport,
                    'approval' => $approvalOutput,
                ],
                'completed_at' => $finalStatus === 'completed' ? now('UTC') : null,
            ])->save();

            $this->auditLogger->record(
                action: 'agents.run.'.$finalStatus,
                resourceType: 'agent',
                resourceId: $run->id,
                metadata: [
                    'agent_id' => $agent->id,
                    'step_count' => $stepNum,
                    'total_tokens' => $totalTokens,
                    'cost_usd' => $totalCost,
                ],
                tenantId: $tenant->id,
                actorUserId: $actor->id,
            );

            return $run;
        } catch (ToolSecurityException $e) {
            $run->fill([
                'status' => 'failed',
                'step_count' => $stepNum,
                'total_tokens' => $totalTokens,
                'cost_usd' => $totalCost,
                'latency_ms' => (int) round((hrtime(true) - $startTime) / 1_000_000),
                'error_message' => Str::limit($e->getMessage(), 950, ''),
                'completed_at' => now('UTC'),
            ])->save();

            throw $e;
        } catch (Throwable $e) {
            $run->fill([
                'status' => 'failed',
                'step_count' => $stepNum,
                'total_tokens' => $totalTokens,
                'cost_usd' => $totalCost,
                'latency_ms' => (int) round((hrtime(true) - $startTime) / 1_000_000),
                'error_message' => Str::limit($e->getMessage(), 950, ''),
                'completed_at' => now('UTC'),
            ])->save();

            throw $e;
        }
    }

    public function cancelRun(Tenant $tenant, User $actor, AgentRun $run): AgentRun
    {
        if (in_array($run->status, ['completed', 'cancelled'], true)) {
            abort(422, "Cannot cancel an agent run in '{$run->status}' state.");
        }

        $run->fill([
            'status' => 'cancelled',
            'completed_at' => now('UTC'),
        ])->save();

        $this->auditLogger->record(
            action: 'agents.run.cancelled',
            resourceType: 'agent',
            resourceId: $run->id,
            metadata: ['agent_id' => $run->agent_id],
            tenantId: $tenant->id,
            actorUserId: $actor->id,
        );

        return $run;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{output: array<string, mixed>, tokens_used: int, cost_usd: string}
     */
    private function runLoggedStep(
        Tenant $tenant,
        User $actor,
        Agent $agent,
        AgentRun $run,
        int $stepNumber,
        string $stepType,
        string $toolName,
        array $arguments,
    ): array {
        $stepStart = hrtime(true);

        try {
            $res = $this->toolRegistry->invoke($tenant, $actor, $agent, $run, $toolName, $arguments);
            $latencyMs = (int) round((hrtime(true) - $stepStart) / 1_000_000);

            AgentStep::query()->create([
                'tenant_id' => $tenant->id,
                'agent_run_id' => $run->id,
                'step_number' => $stepNumber,
                'step_type' => $stepType,
                'tool_name' => $toolName,
                'input_json' => $this->redactLargeChunkInput($arguments),
                'output_json' => $res['output'],
                'status' => $toolName === 'create_approval_request' ? 'awaiting_approval' : 'completed',
                'tokens_used' => $res['tokens_used'],
                'latency_ms' => $latencyMs,
            ]);

            return $res;
        } catch (ToolSecurityException $e) {
            $latencyMs = (int) round((hrtime(true) - $stepStart) / 1_000_000);

            AgentStep::query()->create([
                'tenant_id' => $tenant->id,
                'agent_run_id' => $run->id,
                'step_number' => $stepNumber,
                'step_type' => $stepType,
                'tool_name' => $toolName,
                'input_json' => $this->redactLargeChunkInput($arguments),
                'output_json' => ['error_code' => $e->errorCode, 'details' => $e->details],
                'status' => 'blocked',
                'tokens_used' => 0,
                'latency_ms' => $latencyMs,
                'error_message' => Str::limit($e->getMessage(), 950, ''),
            ]);

            throw $e;
        }
    }

    private function isToolEnabled(string $tenantId, string $agentId, string $toolName): bool
    {
        return AgentTool::query()
            ->where('tenant_id', $tenantId)
            ->where('agent_id', $agentId)
            ->where('tool_name', $toolName)
            ->where('is_enabled', true)
            ->exists();
    }

    /**
     * @param  list<string>  $tools
     */
    private function assertAllowedToolList(Tenant $tenant, User $actor, array $tools): void
    {
        foreach ($tools as $toolName) {
            if (! $this->toolRegistry->isAllowed($toolName)) {
                $this->auditLogger->recordSecurityEvent(
                    eventType: 'agent.unlisted_tool_configured',
                    severity: 'critical',
                    summary: "Blocked attempt to configure unlisted tool '{$toolName}' on an agent",
                    details: ['tool_name' => $toolName],
                    tenantId: $tenant->id,
                    actorUserId: $actor->id,
                );

                throw new ToolSecurityException(
                    errorCode: 'UNLISTED_TOOL',
                    message: "Tool '{$toolName}' is not in the NexaMind AI allowlist.",
                    httpStatus: 403,
                    details: ['tool_name' => $toolName],
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function redactLargeChunkInput(array $arguments): array
    {
        $copy = $arguments;
        if (isset($copy['chunks']) && is_array($copy['chunks'])) {
            $copy['chunks'] = sprintf('[%d authorized retrieved chunks]', count($copy['chunks']));
        }
        if (isset($copy['clauses']) && is_array($copy['clauses'])) {
            $copy['clauses'] = sprintf('[%d extracted policy clauses]', count($copy['clauses']));
        }

        return $copy;
    }
}
