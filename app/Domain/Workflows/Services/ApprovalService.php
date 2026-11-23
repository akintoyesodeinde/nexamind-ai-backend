<?php

declare(strict_types=1);

namespace App\Domain\Workflows\Services;

use App\Domain\Agents\Models\Agent;
use App\Domain\Agents\Models\AgentRun;
use App\Domain\Agents\Models\AgentStep;
use App\Domain\Agents\Services\ToolRegistry;
use App\Domain\Audit\Contracts\AuditLoggerInterface;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Contracts\TenantContextInterface;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Workflows\Models\ApprovalRequest;
use App\Domain\Workflows\Models\OutboxEvent;
use App\Domain\Workflows\Models\WorkflowRun;
use App\Domain\Workflows\Models\WorkflowStep;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ApprovalService
{
    /** @var array<string, int> */
    private const ROLE_RANK = [
        'viewer' => 1,
        'member' => 2,
        'manager' => 3,
        'admin' => 4,
        'owner' => 5,
    ];

    public function __construct(
        private readonly TenantContextInterface $tenantContext,
        private readonly ToolRegistry $toolRegistry,
        private readonly AuditLoggerInterface $auditLogger,
    ) {}

    /**
     * Approves a pending request atomically with zero duplicate side effects under concurrent retries.
     *
     * @return array{approval: ApprovalRequest, already_decided: bool, published_artifact: array<string, mixed>|null}
     */
    public function approve(
        Tenant $tenant,
        User $actor,
        ApprovalRequest $approval,
        ?string $note = null,
    ): array {
        $this->assertAuthorizedApprover($tenant, $actor, $approval);

        $cleanNote = $note !== null ? Str::limit(trim($note), 950, '') : null;

        /** @var array{transitioned: bool, approval: ApprovalRequest} $txResult */
        $txResult = DB::transaction(function () use ($tenant, $actor, $approval, $cleanNote): array {
            $updated = ApprovalRequest::query()
                ->where('tenant_id', $tenant->id)
                ->where('id', $approval->id)
                ->where('status', 'pending')
                ->update([
                    'status' => 'approved',
                    'decided_by' => $actor->id,
                    'decision_note' => $cleanNote,
                    'decided_at' => now('UTC'),
                    'updated_at' => now('UTC'),
                ]);

            $approval->refresh();

            if ($updated === 0) {
                return ['transitioned' => false, 'approval' => $approval];
            }

            $dedupKey = 'approval.decided:'.strtolower($approval->id);
            $exists = OutboxEvent::query()
                ->where('tenant_id', $tenant->id)
                ->where('dedup_key', $dedupKey)
                ->exists();

            if (! $exists) {
                OutboxEvent::query()->create([
                    'tenant_id' => $tenant->id,
                    'aggregate_type' => 'approval_request',
                    'aggregate_id' => $approval->id,
                    'event_type' => 'approvals.approved',
                    'dedup_key' => $dedupKey,
                    'payload_json' => [
                        'approval_request_id' => $approval->id,
                        'decided_by' => $actor->id,
                        'decision' => 'approved',
                        'note' => $cleanNote,
                    ],
                    'status' => 'processed',
                    'processed_at' => now('UTC'),
                ]);
            }

            return ['transitioned' => true, 'approval' => $approval];
        });

        $approval = $txResult['approval'];

        if (! $txResult['transitioned']) {
            if ($approval->status === 'approved') {
                $existingArtifact = $this->findExistingPublishedArtifact($tenant, $approval);

                return [
                    'approval' => $approval,
                    'already_decided' => true,
                    'published_artifact' => $existingArtifact,
                ];
            }

            abort(409, "Approval request has already been decided with status '{$approval->status}'.");
        }

        // First & ONLY transition -> execute gated publication & complete workflow/agent runs
        $publishedArtifact = $this->completeLinkedRunsOnApproval($tenant, $actor, $approval);

        $this->auditLogger->record(
            action: 'approvals.approved',
            resourceType: 'approval_request',
            resourceId: $approval->id,
            metadata: [
                'workflow_run_id' => $approval->workflow_run_id,
                'agent_run_id' => $approval->agent_run_id,
                'note' => $cleanNote,
            ],
            tenantId: $tenant->id,
            actorUserId: $actor->id,
        );

        return [
            'approval' => $approval,
            'already_decided' => false,
            'published_artifact' => $publishedArtifact,
        ];
    }

    /**
     * Rejects a pending approval atomically and cancels linked runs with zero duplicate side effects.
     *
     * @return array{approval: ApprovalRequest, already_decided: bool}
     */
    public function reject(
        Tenant $tenant,
        User $actor,
        ApprovalRequest $approval,
        ?string $note = null,
    ): array {
        $this->assertAuthorizedApprover($tenant, $actor, $approval);

        $cleanNote = $note !== null ? Str::limit(trim($note), 950, '') : null;

        /** @var bool $transitioned */
        $transitioned = DB::transaction(function () use ($tenant, $actor, $approval, $cleanNote): bool {
            $updated = ApprovalRequest::query()
                ->where('tenant_id', $tenant->id)
                ->where('id', $approval->id)
                ->where('status', 'pending')
                ->update([
                    'status' => 'rejected',
                    'decided_by' => $actor->id,
                    'decision_note' => $cleanNote,
                    'decided_at' => now('UTC'),
                    'updated_at' => now('UTC'),
                ]);

            $approval->refresh();
            if ($updated === 0) {
                return false;
            }

            $dedupKey = 'approval.decided:'.strtolower($approval->id);
            if (! OutboxEvent::query()->where('tenant_id', $tenant->id)->where('dedup_key', $dedupKey)->exists()) {
                OutboxEvent::query()->create([
                    'tenant_id' => $tenant->id,
                    'aggregate_type' => 'approval_request',
                    'aggregate_id' => $approval->id,
                    'event_type' => 'approvals.rejected',
                    'dedup_key' => $dedupKey,
                    'payload_json' => [
                        'approval_request_id' => $approval->id,
                        'decided_by' => $actor->id,
                        'decision' => 'rejected',
                        'note' => $cleanNote,
                    ],
                    'status' => 'processed',
                    'processed_at' => now('UTC'),
                ]);
            }

            if ($approval->agent_run_id !== null) {
                AgentRun::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('id', $approval->agent_run_id)
                    ->update([
                        'status' => 'cancelled',
                        'error_message' => $cleanNote ?? 'Rejected during human approval review.',
                        'completed_at' => now('UTC'),
                    ]);
            }

            if ($approval->workflow_run_id !== null) {
                WorkflowStep::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('workflow_run_id', $approval->workflow_run_id)
                    ->where('step_key', 'human_approval_gate')
                    ->update([
                        'status' => 'rejected',
                        'completed_at' => now('UTC'),
                    ]);

                WorkflowStep::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('workflow_run_id', $approval->workflow_run_id)
                    ->where('step_key', 'publish_approved_artifact')
                    ->update(['status' => 'skipped']);

                WorkflowRun::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('id', $approval->workflow_run_id)
                    ->update([
                        'status' => 'cancelled',
                        'error_message' => $cleanNote ?? 'Rejected by approver.',
                        'completed_at' => now('UTC'),
                    ]);
            }

            return true;
        });

        if (! $transitioned) {
            if ($approval->status === 'rejected') {
                return ['approval' => $approval, 'already_decided' => true];
            }
            abort(409, "Approval request has already been decided with status '{$approval->status}'.");
        }

        $this->auditLogger->record(
            action: 'approvals.rejected',
            resourceType: 'approval_request',
            resourceId: $approval->id,
            metadata: [
                'workflow_run_id' => $approval->workflow_run_id,
                'agent_run_id' => $approval->agent_run_id,
                'note' => $cleanNote,
            ],
            tenantId: $tenant->id,
            actorUserId: $actor->id,
        );

        return ['approval' => $approval, 'already_decided' => false];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function completeLinkedRunsOnApproval(
        Tenant $tenant,
        User $actor,
        ApprovalRequest $approval,
    ): ?array {
        $publishedArtifact = null;

        if ($approval->agent_run_id !== null) {
            /** @var AgentRun|null $agentRun */
            $agentRun = AgentRun::query()
                ->where('tenant_id', $tenant->id)
                ->where('id', $approval->agent_run_id)
                ->first();

            if ($agentRun !== null) {
                /** @var Agent|null $agent */
                $agent = Agent::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('id', $agentRun->agent_id)
                    ->first();

                if ($agent !== null) {
                    $stepStart = hrtime(true);
                    $publishResult = $this->toolRegistry->invoke(
                        tenant: $tenant,
                        user: $actor,
                        agent: $agent,
                        run: $agentRun,
                        toolName: 'publish_approved_report',
                        arguments: [
                            'approval_request_id' => $approval->id,
                            'channel' => 'compliance_archive',
                        ],
                        bypassAgentToolToggle: true,
                    );
                    $publishedArtifact = $publishResult['output'];

                    $nextStepNum = ((int) $agentRun->step_count) + 1;
                    AgentStep::query()->create([
                        'tenant_id' => $tenant->id,
                        'agent_run_id' => $agentRun->id,
                        'step_number' => $nextStepNum,
                        'step_type' => 'publish',
                        'tool_name' => 'publish_approved_report',
                        'input_json' => [
                            'approval_request_id' => $approval->id,
                            'channel' => 'compliance_archive',
                        ],
                        'output_json' => $publishedArtifact,
                        'status' => 'completed',
                        'tokens_used' => 0,
                        'latency_ms' => (int) round((hrtime(true) - $stepStart) / 1_000_000),
                    ]);

                    $existingOutput = $agentRun->output_json ?? [];
                    $existingOutput['published_artifact'] = $publishedArtifact;

                    $agentRun->fill([
                        'status' => 'completed',
                        'step_count' => $nextStepNum,
                        'output_json' => $existingOutput,
                        'completed_at' => now('UTC'),
                    ])->save();
                }
            }
        }

        if ($approval->workflow_run_id !== null) {
            /** @var WorkflowRun|null $workflowRun */
            $workflowRun = WorkflowRun::query()
                ->where('tenant_id', $tenant->id)
                ->where('id', $approval->workflow_run_id)
                ->first();

            if ($workflowRun !== null) {
                WorkflowStep::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('workflow_run_id', $workflowRun->id)
                    ->where('step_key', 'human_approval_gate')
                    ->update([
                        'status' => 'completed',
                        'completed_at' => now('UTC'),
                    ]);

                WorkflowStep::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('workflow_run_id', $workflowRun->id)
                    ->where('step_key', 'publish_approved_artifact')
                    ->update([
                        'status' => 'completed',
                        'started_at' => now('UTC'),
                        'completed_at' => now('UTC'),
                        'payload_json' => json_encode($publishedArtifact ?? ['published' => true], JSON_THROW_ON_ERROR),
                    ]);

                $wfOutput = $workflowRun->output_json ?? [];
                $wfOutput['published_artifact'] = $publishedArtifact ?? [
                    'published' => true,
                    'approval_request_id' => $approval->id,
                    'approved_by' => $actor->id,
                    'published_at' => now('UTC')->toISOString(),
                ];

                $workflowRun->fill([
                    'status' => 'completed',
                    'output_json' => $wfOutput,
                    'completed_at' => now('UTC'),
                ])->save();

                $this->auditLogger->record(
                    action: 'workflows.run.completed',
                    resourceType: 'workflow',
                    resourceId: $workflowRun->id,
                    metadata: ['approval_request_id' => $approval->id],
                    tenantId: $tenant->id,
                    actorUserId: $actor->id,
                );
            }
        }

        return $publishedArtifact;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findExistingPublishedArtifact(Tenant $tenant, ApprovalRequest $approval): ?array
    {
        if ($approval->workflow_run_id !== null) {
            /** @var WorkflowRun|null $wf */
            $wf = WorkflowRun::query()
                ->where('tenant_id', $tenant->id)
                ->where('id', $approval->workflow_run_id)
                ->first();
            $out = $wf?->output_json;
            if (is_array($out) && is_array($out['published_artifact'] ?? null)) {
                return $out['published_artifact'];
            }
        }

        return null;
    }

    private function assertAuthorizedApprover(Tenant $tenant, User $actor, ApprovalRequest $approval): void
    {
        $roleSlug = strtolower((string) ($this->tenantContext->roleSlug() ?? 'viewer'));
        $actorRank = self::ROLE_RANK[$roleSlug] ?? 0;
        $requiredRank = self::ROLE_RANK[strtolower($approval->required_role)] ?? 3;

        $isAssignedMismatch = $approval->assigned_approver_id !== null
            && strcasecmp($approval->assigned_approver_id, $actor->id) !== 0
            && $actorRank < self::ROLE_RANK['admin'];

        if ($actorRank < $requiredRank || $isAssignedMismatch) {
            $this->auditLogger->recordSecurityEvent(
                eventType: 'approvals.unauthorized_decision_attempt',
                severity: 'high',
                summary: 'User without sufficient approver role attempted to decide an approval request',
                details: [
                    'approval_request_id' => $approval->id,
                    'actor_role' => $roleSlug,
                    'required_role' => $approval->required_role,
                ],
                tenantId: $tenant->id,
                actorUserId: $actor->id,
            );
            abort(403, "Decision requires '{$approval->required_role}' role or higher.");
        }
    }
}
