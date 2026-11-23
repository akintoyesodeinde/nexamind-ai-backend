<?php

declare(strict_types=1);

namespace App\Domain\Workflows\Services;

use App\Domain\Agents\Models\Agent;
use App\Domain\Agents\Services\AgentOrchestratorService;
use App\Domain\Audit\Contracts\AuditLoggerInterface;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Workflows\Models\OutboxEvent;
use App\Domain\Workflows\Models\WorkflowDefinition;
use App\Domain\Workflows\Models\WorkflowRun;
use App\Domain\Workflows\Models\WorkflowStep;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class WorkflowService
{
    public function __construct(
        private readonly AgentOrchestratorService $agentOrchestrator,
        private readonly AuditLoggerInterface $auditLogger,
    ) {}

    /**
     * Seeds or retrieves the flagship portfolio AI Compliance Report Workflow & its linked agent.
     */
    public function ensureComplianceReportTemplate(Tenant $tenant, User $actor): WorkflowDefinition
    {
        /** @var WorkflowDefinition|null $existing */
        $existing = WorkflowDefinition::query()
            ->where('tenant_id', $tenant->id)
            ->where('slug', 'ai-compliance-report-workflow')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        /** @var Agent|null $agent */
        $agent = Agent::query()
            ->where('tenant_id', $tenant->id)
            ->where('slug', 'compliance-report-agent')
            ->first();

        if ($agent === null) {
            $agent = $this->agentOrchestrator->createAgent($tenant, $actor, [
                'name' => 'Compliance Report Agent',
                'description' => 'Retrieves approved HR & compliance policies, extracts obligations, drafts cited compliance reports, and routes publication for manager approval.',
                'system_prompt' => 'You are an enterprise compliance analyst. Use only authorized retrieved policy chunks, cite every finding strictly with [n] tags, never fabricate references, and always require human manager approval before publishing reports.',
                'model_alias' => 'default-chat',
                'max_steps' => 8,
                'max_tokens_per_run' => 16000,
                'require_approval' => true,
                'tools' => [
                    'search_knowledge_base',
                    'extract_document_clauses',
                    'draft_compliance_report',
                    'create_approval_request',
                    'publish_approved_report',
                ],
            ]);
        }

        return $this->createDefinition($tenant, $actor, [
            'name' => 'AI Compliance Report Workflow',
            'slug' => 'ai-compliance-report-workflow',
            'description' => 'End-to-end governed compliance reporting: ACL vector retrieval -> structured clause extraction -> citation-verified report draft -> Manager approval gate -> audited publication.',
            'workflow_type' => 'compliance_report',
            'agent_id' => $agent->id,
            'approver_role' => 'manager',
            'steps_definition' => self::defaultComplianceSteps(),
        ]);
    }

    /**
     * @param  array{
     *     name: string,
     *     slug?: string,
     *     description?: string|null,
     *     workflow_type?: string,
     *     agent_id?: string|null,
     *     approver_role?: string,
     *     steps_definition?: list<array<string, mixed>>
     * }  $payload
     */
    public function createDefinition(Tenant $tenant, User $actor, array $payload): WorkflowDefinition
    {
        $baseSlug = isset($payload['slug']) && trim($payload['slug']) !== ''
            ? Str::slug($payload['slug'])
            : Str::slug($payload['name']);
        $slug = $baseSlug !== '' ? $baseSlug : 'workflow';
        $suffix = 1;
        while (WorkflowDefinition::query()->where('tenant_id', $tenant->id)->where('slug', $slug)->exists()) {
            $suffix++;
            $slug = Str::limit($baseSlug, 100, '').'-'.$suffix;
        }

        $agentId = $payload['agent_id'] ?? null;
        if (is_string($agentId) && trim($agentId) !== '') {
            $agentExists = Agent::query()->where('tenant_id', $tenant->id)->where('id', $agentId)->exists();
            if (! $agentExists) {
                abort(404, 'Referenced agent was not found in this organization.');
            }
        } else {
            $agentId = null;
        }

        $definition = WorkflowDefinition::query()->create([
            'tenant_id' => $tenant->id,
            'agent_id' => $agentId,
            'created_by' => $actor->id,
            'name' => trim($payload['name']),
            'slug' => $slug,
            'description' => isset($payload['description']) ? trim((string) $payload['description']) : null,
            'workflow_type' => $payload['workflow_type'] ?? 'compliance_report',
            'steps_definition_json' => $payload['steps_definition'] ?? self::defaultComplianceSteps(),
            'approver_role' => $payload['approver_role'] ?? 'manager',
            'is_active' => true,
        ]);

        $this->auditLogger->record(
            action: 'workflows.definition.created',
            resourceType: 'workflow',
            resourceId: $definition->id,
            metadata: ['slug' => $definition->slug, 'approver_role' => $definition->approver_role],
            tenantId: $tenant->id,
            actorUserId: $actor->id,
        );

        return $definition;
    }

    /**
     * Launches a stateful workflow run with transactional outbox + human approval orchestration.
     *
     * @param  array<string, mixed>  $input
     */
    public function launchRun(
        Tenant $tenant,
        User $actor,
        WorkflowDefinition $definition,
        array $input = [],
    ): WorkflowRun {
        if (! $definition->is_active) {
            abort(422, 'Cannot launch a disabled workflow definition.');
        }

        $agent = $this->resolveWorkflowAgent($tenant, $actor, $definition);
        $goal = isset($input['goal']) && is_string($input['goal']) && trim($input['goal']) !== ''
            ? trim($input['goal'])
            : 'Compile an organizational compliance summary covering approved HR, remote work, and security policies.';

        $reportTitle = isset($input['report_title']) && is_string($input['report_title']) && trim($input['report_title']) !== ''
            ? trim($input['report_title'])
            : $definition->name.' — '.now('UTC')->format('Y-m-d');

        /** @var WorkflowRun $workflowRun */
        $workflowRun = DB::transaction(function () use ($tenant, $actor, $definition, $input, $goal, $reportTitle): WorkflowRun {
            $run = WorkflowRun::query()->create([
                'tenant_id' => $tenant->id,
                'workflow_definition_id' => $definition->id,
                'triggered_by' => $actor->id,
                'status' => 'running',
                'input_json' => array_merge($input, [
                    'goal' => $goal,
                    'report_title' => $reportTitle,
                ]),
                'started_at' => now('UTC'),
            ]);

            $stepsDef = $definition->steps_definition_json;
            if ($stepsDef === []) {
                $stepsDef = self::defaultComplianceSteps();
            }

            foreach ($stepsDef as $idx => $stepDef) {
                WorkflowStep::query()->create([
                    'tenant_id' => $tenant->id,
                    'workflow_run_id' => $run->id,
                    'step_order' => $idx + 1,
                    'step_key' => (string) ($stepDef['key'] ?? 'step_'.($idx + 1)),
                    'name' => (string) ($stepDef['name'] ?? 'Workflow Step '.($idx + 1)),
                    'status' => 'pending',
                ]);
            }

            OutboxEvent::query()->create([
                'tenant_id' => $tenant->id,
                'aggregate_type' => 'workflow_run',
                'aggregate_id' => $run->id,
                'event_type' => 'workflows.run.started',
                'dedup_key' => 'workflow.run.started:'.strtolower($run->id),
                'payload_json' => [
                    'workflow_run_id' => $run->id,
                    'workflow_definition_id' => $definition->id,
                    'goal' => $goal,
                ],
                'status' => 'processed',
                'processed_at' => now('UTC'),
            ]);

            return $run;
        });

        $agentInput = [
            'report_title' => $reportTitle,
            'approver_role' => $definition->approver_role,
            'top_k' => isset($input['top_k']) ? (int) $input['top_k'] : 6,
        ];
        if (isset($input['knowledge_base_ids']) && is_array($input['knowledge_base_ids']) && $input['knowledge_base_ids'] !== []) {
            $agentInput['knowledge_base_ids'] = $input['knowledge_base_ids'];
        }

        $agentRun = $this->agentOrchestrator->executeRun(
            tenant: $tenant,
            actor: $actor,
            agent: $agent,
            goal: $goal,
            input: $agentInput,
            workflowRunId: $workflowRun->id,
        );

        $agentOutput = $agentRun->output_json ?? [];
        $awaitingApproval = $agentRun->status === 'awaiting_approval';

        DB::transaction(function () use ($tenant, $workflowRun, $agentRun, $agentOutput, $awaitingApproval): void {
            $steps = WorkflowStep::query()
                ->where('tenant_id', $tenant->id)
                ->where('workflow_run_id', $workflowRun->id)
                ->orderBy('step_order')
                ->get();

            foreach ($steps as $step) {
                if ($step->step_key === 'retrieve_policies') {
                    $step->fill([
                        'status' => 'completed',
                        'started_at' => $agentRun->started_at,
                        'completed_at' => now('UTC'),
                        'payload_json' => [
                            'retrieved_chunks_count' => $agentOutput['retrieved_chunks_count'] ?? 0,
                            'clauses_count' => $agentOutput['clauses_count'] ?? 0,
                        ],
                    ])->save();
                } elseif ($step->step_key === 'draft_and_validate_report') {
                    $step->fill([
                        'status' => 'completed',
                        'started_at' => $agentRun->started_at,
                        'completed_at' => now('UTC'),
                        'payload_json' => [
                            'report' => $agentOutput['report'] ?? null,
                            'tokens_used' => $agentRun->total_tokens,
                            'cost_usd' => $agentRun->cost_usd,
                        ],
                    ])->save();
                } elseif ($step->step_key === 'human_approval_gate') {
                    $step->fill([
                        'status' => $awaitingApproval ? 'awaiting_approval' : 'completed',
                        'started_at' => now('UTC'),
                        'completed_at' => $awaitingApproval ? null : now('UTC'),
                        'payload_json' => [
                            'approval' => $agentOutput['approval'] ?? null,
                        ],
                    ])->save();
                } elseif ($step->step_key === 'publish_approved_artifact' && ! $awaitingApproval) {
                    $step->fill([
                        'status' => 'completed',
                        'started_at' => now('UTC'),
                        'completed_at' => now('UTC'),
                    ])->save();
                }
            }

            $workflowRun->fill([
                'agent_run_id' => $agentRun->id,
                'status' => $awaitingApproval ? 'awaiting_approval' : 'completed',
                'output_json' => [
                    'draft_report' => $agentOutput['report'] ?? null,
                    'approval' => $agentOutput['approval'] ?? null,
                    'published_artifact' => null,
                ],
                'completed_at' => $awaitingApproval ? null : now('UTC'),
            ])->save();
        });

        $this->auditLogger->record(
            action: 'workflows.run.launched',
            resourceType: 'workflow',
            resourceId: $workflowRun->id,
            metadata: [
                'workflow_definition_id' => $definition->id,
                'agent_run_id' => $agentRun->id,
                'status' => $workflowRun->status,
            ],
            tenantId: $tenant->id,
            actorUserId: $actor->id,
        );

        $workflowRun->refresh();

        return $workflowRun;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function defaultComplianceSteps(): array
    {
        return [
            [
                'key' => 'retrieve_policies',
                'name' => 'Retrieve Authorized Policies & Extract Clauses',
                'type' => 'agent_retrieval',
            ],
            [
                'key' => 'draft_and_validate_report',
                'name' => 'Draft & Schema-Validate Cited Compliance Report',
                'type' => 'agent_synthesis',
            ],
            [
                'key' => 'human_approval_gate',
                'name' => 'Manager Approval Gate',
                'type' => 'human_approval',
            ],
            [
                'key' => 'publish_approved_artifact',
                'name' => 'Publish Approved Compliance Artifact & Audit Trail',
                'type' => 'publish_artifact',
            ],
        ];
    }

    private function resolveWorkflowAgent(Tenant $tenant, User $actor, WorkflowDefinition $definition): Agent
    {
        if ($definition->agent_id !== null) {
            /** @var Agent|null $linked */
            $linked = Agent::query()->where('tenant_id', $tenant->id)->where('id', $definition->agent_id)->first();
            if ($linked !== null && $linked->status === 'active') {
                return $linked;
            }
        }

        /** @var Agent|null $existing */
        $existing = Agent::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', 'active')
            ->orderBy('created_at')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return $this->agentOrchestrator->createAgent($tenant, $actor, [
            'name' => 'Compliance Report Agent',
            'description' => 'Default workflow execution agent.',
            'system_prompt' => 'Produce grounded, citation-verified compliance reports routed for human approval.',
            'require_approval' => true,
        ]);
    }
}
