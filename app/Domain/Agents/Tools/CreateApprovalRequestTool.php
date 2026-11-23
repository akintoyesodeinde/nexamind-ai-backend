<?php

declare(strict_types=1);

namespace App\Domain\Agents\Tools;

use App\Domain\Agents\Contracts\AgentToolInterface;
use App\Domain\Agents\Models\Agent;
use App\Domain\Agents\Models\AgentRun;
use App\Domain\Audit\Contracts\AuditLoggerInterface;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Workflows\Models\ApprovalRequest;
use App\Domain\Workflows\Models\OutboxEvent;
use Illuminate\Support\Facades\DB;

final class CreateApprovalRequestTool implements AgentToolInterface
{
    public function __construct(
        private readonly AuditLoggerInterface $auditLogger,
    ) {}

    public function name(): string
    {
        return 'create_approval_request';
    }

    public function description(): string
    {
        return 'Pause execution and route a drafted artifact to authorized human approvers via the Approval Inbox.';
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
            'required' => ['title', 'summary', 'payload'],
            'properties' => [
                'title' => [
                    'type' => 'string',
                    'minLength' => 3,
                    'maxLength' => 200,
                ],
                'summary' => [
                    'type' => 'string',
                    'minLength' => 3,
                    'maxLength' => 2000,
                ],
                'action_type' => [
                    'type' => 'string',
                    'enum' => ['publish_approved_report', 'policy_notification', 'agent_action'],
                ],
                'required_role' => [
                    'type' => 'string',
                    'enum' => ['owner', 'admin', 'manager', 'member'],
                ],
                'workflow_run_id' => [
                    'type' => 'string',
                ],
                'payload' => [
                    'type' => 'object',
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
        $title = trim((string) $arguments['title']);
        $summary = trim((string) $arguments['summary']);
        $actionType = isset($arguments['action_type']) ? (string) $arguments['action_type'] : 'publish_approved_report';
        $requiredRole = isset($arguments['required_role']) ? (string) $arguments['required_role'] : 'manager';
        $workflowRunId = isset($arguments['workflow_run_id']) && is_string($arguments['workflow_run_id']) && trim($arguments['workflow_run_id']) !== ''
            ? trim($arguments['workflow_run_id'])
            : null;
        /** @var array<string, mixed> $payload */
        $payload = is_array($arguments['payload']) ? $arguments['payload'] : [];

        /** @var ApprovalRequest $approval */
        $approval = DB::transaction(function () use (
            $tenant,
            $user,
            $run,
            $workflowRunId,
            $requiredRole,
            $title,
            $actionType,
            $summary,
            $payload,
        ): ApprovalRequest {
            $approval = ApprovalRequest::query()->create([
                'tenant_id' => $tenant->id,
                'workflow_run_id' => $workflowRunId,
                'agent_run_id' => $run->id,
                'requested_by' => $user->id,
                'required_role' => $requiredRole,
                'title' => $title,
                'action_type' => $actionType,
                'summary' => $summary,
                'payload_json' => $payload,
                'status' => 'pending',
            ]);

            OutboxEvent::query()->create([
                'tenant_id' => $tenant->id,
                'aggregate_type' => 'approval_request',
                'aggregate_id' => $approval->id,
                'event_type' => 'approvals.requested',
                'dedup_key' => 'approval.requested:'.strtolower($approval->id),
                'payload_json' => [
                    'approval_request_id' => $approval->id,
                    'workflow_run_id' => $workflowRunId,
                    'agent_run_id' => $run->id,
                    'title' => $title,
                    'required_role' => $requiredRole,
                ],
                'status' => 'pending',
            ]);

            return $approval;
        });

        $this->auditLogger->record(
            action: 'approvals.requested',
            resourceType: 'approval_request',
            resourceId: $approval->id,
            metadata: [
                'agent_id' => $agent->id,
                'agent_run_id' => $run->id,
                'workflow_run_id' => $workflowRunId,
                'required_role' => $requiredRole,
            ],
            tenantId: $tenant->id,
            actorUserId: $user->id,
        );

        return [
            'output' => [
                'approval_request_id' => $approval->id,
                'status' => $approval->status,
                'required_role' => $approval->required_role,
                'title' => $approval->title,
            ],
            'tokens_used' => 0,
            'cost_usd' => '0.000000',
        ];
    }
}
