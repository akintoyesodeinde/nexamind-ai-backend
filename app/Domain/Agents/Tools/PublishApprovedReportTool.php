<?php

declare(strict_types=1);

namespace App\Domain\Agents\Tools;

use App\Domain\Agents\Contracts\AgentToolInterface;
use App\Domain\Agents\Exceptions\ToolSecurityException;
use App\Domain\Agents\Models\Agent;
use App\Domain\Agents\Models\AgentRun;
use App\Domain\Audit\Contracts\AuditLoggerInterface;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Workflows\Models\ApprovalRequest;
use App\Domain\Workflows\Models\OutboxEvent;
use Illuminate\Support\Facades\DB;

final class PublishApprovedReportTool implements AgentToolInterface
{
    public function __construct(
        private readonly AuditLoggerInterface $auditLogger,
    ) {}

    public function name(): string
    {
        return 'publish_approved_report';
    }

    public function description(): string
    {
        return 'Publish a compliance report artifact ONLY after verifying a tenant-scoped approved human approval record.';
    }

    public function requiresApproval(): bool
    {
        return true;
    }

    public function riskLevel(): string
    {
        return 'high';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['approval_request_id'],
            'properties' => [
                'approval_request_id' => [
                    'type' => 'string',
                    'minLength' => 36,
                    'maxLength' => 36,
                ],
                'channel' => [
                    'type' => 'string',
                    'enum' => ['internal_portal', 'compliance_archive'],
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
        $approvalId = trim((string) $arguments['approval_request_id']);
        $channel = isset($arguments['channel']) ? (string) $arguments['channel'] : 'compliance_archive';

        /** @var ApprovalRequest|null $approval */
        $approval = ApprovalRequest::withoutGlobalScopes()
            ->where('id', $approvalId)
            ->where('tenant_id', $tenant->id)
            ->first();

        if ($approval === null || $approval->status !== 'approved' || $approval->decided_by === null) {
            $this->auditLogger->recordSecurityEvent(
                eventType: 'agent.unapproved_publish_blocked',
                severity: 'critical',
                summary: 'Blocked attempt to publish report without a verified tenant-scoped approved human approval record',
                details: [
                    'agent_id' => $agent->id,
                    'agent_run_id' => $run->id,
                    'approval_request_id' => $approvalId,
                    'actual_status' => $approval !== null ? $approval->status : 'not_found_or_cross_tenant',
                ],
                tenantId: $tenant->id,
                actorUserId: $user->id,
            );

            throw new ToolSecurityException(
                errorCode: 'UNAPPROVED_PUBLISH',
                message: 'Publishing a report requires a verified approved human approval record in this tenant.',
                httpStatus: 403,
                details: ['approval_request_id' => $approvalId],
            );
        }

        $dedupKey = 'report.published:'.strtolower($approval->id);
        $publishedAt = now('UTC')->toISOString();

        $artifact = [
            'published' => true,
            'channel' => $channel,
            'approval_request_id' => $approval->id,
            'approved_by' => $approval->decided_by,
            'approved_at' => $approval->decided_at?->toISOString(),
            'published_at' => $publishedAt,
            'report' => $approval->payload_json,
        ];

        DB::transaction(function () use ($tenant, $approval, $dedupKey, $artifact): void {
            $exists = OutboxEvent::query()
                ->where('tenant_id', $tenant->id)
                ->where('dedup_key', $dedupKey)
                ->exists();

            if (! $exists) {
                OutboxEvent::query()->create([
                    'tenant_id' => $tenant->id,
                    'aggregate_type' => 'approval_request',
                    'aggregate_id' => $approval->id,
                    'event_type' => 'workflow.report.published',
                    'dedup_key' => $dedupKey,
                    'payload_json' => $artifact,
                    'status' => 'processed',
                    'processed_at' => now('UTC'),
                ]);
            }
        });

        $this->auditLogger->record(
            action: 'agents.report.published',
            resourceType: 'approval_request',
            resourceId: $approval->id,
            metadata: [
                'agent_id' => $agent->id,
                'agent_run_id' => $run->id,
                'channel' => $channel,
                'approved_by' => $approval->decided_by,
            ],
            tenantId: $tenant->id,
            actorUserId: $user->id,
        );

        return [
            'output' => $artifact,
            'tokens_used' => 0,
            'cost_usd' => '0.000000',
        ];
    }
}
