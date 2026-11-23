<?php

declare(strict_types=1);

namespace App\Domain\Agents\Services;

use App\Domain\Agents\Contracts\AgentToolInterface;
use App\Domain\Agents\Exceptions\ToolSecurityException;
use App\Domain\Agents\Models\Agent;
use App\Domain\Agents\Models\AgentRun;
use App\Domain\Agents\Models\AgentTool;
use App\Domain\Agents\Tools\CreateApprovalRequestTool;
use App\Domain\Agents\Tools\DraftComplianceReportTool;
use App\Domain\Agents\Tools\ExtractDocumentClausesTool;
use App\Domain\Agents\Tools\PublishApprovedReportTool;
use App\Domain\Agents\Tools\SearchKnowledgeBaseTool;
use App\Domain\Audit\Contracts\AuditLoggerInterface;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\Tenant;

/**
 * Hard-allowlisted tool registry.
 * Security invariant: No SQL execution, no shell/OS command, and no arbitrary outbound HTTP tools exist.
 */
final class ToolRegistry
{
    /** @var array<string, AgentToolInterface> */
    private array $tools = [];

    public function __construct(
        SearchKnowledgeBaseTool $searchTool,
        ExtractDocumentClausesTool $extractTool,
        DraftComplianceReportTool $draftTool,
        CreateApprovalRequestTool $approvalTool,
        PublishApprovedReportTool $publishTool,
        private readonly JsonSchemaValidator $schemaValidator,
        private readonly AuditLoggerInterface $auditLogger,
    ) {
        foreach ([$searchTool, $extractTool, $draftTool, $approvalTool, $publishTool] as $tool) {
            $this->tools[$tool->name()] = $tool;
        }
    }

    /**
     * @return list<string>
     */
    public function allowedToolNames(): array
    {
        return array_keys($this->tools);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function catalog(): array
    {
        $items = [];
        foreach ($this->tools as $tool) {
            $items[] = [
                'name' => $tool->name(),
                'description' => $tool->description(),
                'requires_approval' => $tool->requiresApproval(),
                'risk_level' => $tool->riskLevel(),
                'input_schema' => $tool->inputSchema(),
            ];
        }

        return $items;
    }

    public function isAllowed(string $toolName): bool
    {
        return array_key_exists($toolName, $this->tools);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{
     *     output: array<string, mixed>,
     *     tokens_used: int,
     *     cost_usd: string
     * }
     */
    public function invoke(
        Tenant $tenant,
        User $user,
        Agent $agent,
        AgentRun $run,
        string $toolName,
        array $arguments,
        bool $bypassAgentToolToggle = false,
    ): array {
        if (! isset($this->tools[$toolName])) {
            $this->auditLogger->recordSecurityEvent(
                eventType: 'agent.unlisted_tool_blocked',
                severity: 'critical',
                summary: "Blocked attempt to invoke unlisted or dangerous tool '{$toolName}'",
                details: [
                    'agent_id' => $agent->id,
                    'agent_run_id' => $run->id,
                    'tool_name' => $toolName,
                ],
                tenantId: $tenant->id,
                actorUserId: $user->id,
            );

            throw new ToolSecurityException(
                errorCode: 'UNLISTED_TOOL',
                message: "Tool '{$toolName}' is not in the NexaMind AI allowlist and is strictly prohibited.",
                httpStatus: 403,
                details: ['tool_name' => $toolName],
            );
        }

        if (! $bypassAgentToolToggle) {
            $enabled = AgentTool::query()
                ->where('tenant_id', $tenant->id)
                ->where('agent_id', $agent->id)
                ->where('tool_name', $toolName)
                ->where('is_enabled', true)
                ->exists();

            if (! $enabled) {
                $this->auditLogger->recordSecurityEvent(
                    eventType: 'agent.tool_not_enabled',
                    severity: 'high',
                    summary: "Blocked attempt to invoke disabled tool '{$toolName}' on agent",
                    details: [
                        'agent_id' => $agent->id,
                        'agent_run_id' => $run->id,
                        'tool_name' => $toolName,
                    ],
                    tenantId: $tenant->id,
                    actorUserId: $user->id,
                );

                throw new ToolSecurityException(
                    errorCode: 'TOOL_DISABLED',
                    message: "Tool '{$toolName}' is not enabled on this agent.",
                    httpStatus: 403,
                    details: ['tool_name' => $toolName],
                );
            }
        }

        $tool = $this->tools[$toolName];
        $schemaErrors = $this->schemaValidator->validate($arguments, $tool->inputSchema());
        if ($schemaErrors !== []) {
            $this->auditLogger->recordSecurityEvent(
                eventType: 'agent.tool_schema_invalid',
                severity: 'medium',
                summary: "Blocked tool '{$toolName}' invocation due to JSON Schema validation errors",
                details: [
                    'agent_id' => $agent->id,
                    'agent_run_id' => $run->id,
                    'tool_name' => $toolName,
                    'errors' => $schemaErrors,
                ],
                tenantId: $tenant->id,
                actorUserId: $user->id,
            );

            throw new ToolSecurityException(
                errorCode: 'TOOL_SCHEMA_INVALID',
                message: "Arguments for tool '{$toolName}' failed JSON Schema validation.",
                httpStatus: 422,
                details: ['validation_errors' => $schemaErrors],
            );
        }

        return $tool->execute($tenant, $user, $agent, $run, $arguments);
    }
}
