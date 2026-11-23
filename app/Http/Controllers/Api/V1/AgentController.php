<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Agents\Models\Agent;
use App\Domain\Agents\Models\AgentRun;
use App\Domain\Agents\Services\AgentOrchestratorService;
use App\Domain\Agents\Services\ToolRegistry;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Contracts\TenantContextInterface;
use App\Http\Controllers\Controller;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AgentController extends Controller
{
    public function __construct(
        private readonly TenantContextInterface $tenantContext,
        private readonly AgentOrchestratorService $orchestrator,
        private readonly ToolRegistry $toolRegistry,
    ) {}

    public function toolsCatalog(Request $request): JsonResponse
    {
        return ApiResponse::success($this->toolRegistry->catalog(), 200, $request);
    }

    public function index(Request $request): JsonResponse
    {
        $tenant = $this->tenantContext->tenant();
        if ($tenant === null) {
            return ApiResponse::error('TENANT_REQUIRED', 'No active tenant context.', 400, null, $request);
        }

        $agents = Agent::query()
            ->where('tenant_id', $tenant->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(static fn (Agent $agent): array => $agent->toApiArray())
            ->values()
            ->all();

        return ApiResponse::success($agents, 200, $request);
    }

    public function store(Request $request): JsonResponse
    {
        $tenant = $this->tenantContext->tenant();
        /** @var User $user */
        $user = $request->user();
        if ($tenant === null) {
            return ApiResponse::error('TENANT_REQUIRED', 'No active tenant context.', 400, null, $request);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'description' => ['nullable', 'string', 'max:500'],
            'system_prompt' => ['required', 'string', 'min:5', 'max:8000'],
            'model_alias' => ['sometimes', 'string', 'max:100'],
            'allowed_kb_ids' => ['sometimes', 'array'],
            'allowed_kb_ids.*' => ['uuid'],
            'max_steps' => ['sometimes', 'integer', 'min:1', 'max:12'],
            'max_tokens_per_run' => ['sometimes', 'integer', 'min:500', 'max:100000'],
            'require_approval' => ['sometimes', 'boolean'],
            'tools' => ['sometimes', 'array'],
            'tools.*' => ['string', 'max:80'],
        ]);

        $agent = $this->orchestrator->createAgent($tenant, $user, $validated);

        return ApiResponse::success($agent->toApiArray(), 201, $request);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $tenant = $this->tenantContext->tenant();
        if ($tenant === null) {
            return ApiResponse::error('TENANT_REQUIRED', 'No active tenant context.', 400, null, $request);
        }

        /** @var Agent|null $agent */
        $agent = Agent::query()->where('tenant_id', $tenant->id)->where('id', $id)->first();
        if ($agent === null) {
            return ApiResponse::error('NOT_FOUND', 'Agent not found.', 404, null, $request);
        }

        return ApiResponse::success($agent->toApiArray(), 200, $request);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $tenant = $this->tenantContext->tenant();
        /** @var User $user */
        $user = $request->user();
        if ($tenant === null) {
            return ApiResponse::error('TENANT_REQUIRED', 'No active tenant context.', 400, null, $request);
        }

        /** @var Agent|null $agent */
        $agent = Agent::query()->where('tenant_id', $tenant->id)->where('id', $id)->first();
        if ($agent === null) {
            return ApiResponse::error('NOT_FOUND', 'Agent not found.', 404, null, $request);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:160'],
            'description' => ['nullable', 'string', 'max:500'],
            'system_prompt' => ['sometimes', 'string', 'min:5', 'max:8000'],
            'model_alias' => ['sometimes', 'string', 'max:100'],
            'allowed_kb_ids' => ['sometimes', 'array'],
            'allowed_kb_ids.*' => ['uuid'],
            'max_steps' => ['sometimes', 'integer', 'min:1', 'max:12'],
            'max_tokens_per_run' => ['sometimes', 'integer', 'min:500', 'max:100000'],
            'require_approval' => ['sometimes', 'boolean'],
            'status' => ['sometimes', 'in:active,disabled'],
            'tools' => ['sometimes', 'array'],
            'tools.*' => ['string', 'max:80'],
        ]);

        $updated = $this->orchestrator->updateAgent($tenant, $user, $agent, $validated);

        return ApiResponse::success($updated->toApiArray(), 200, $request);
    }

    public function run(Request $request, string $id): JsonResponse
    {
        $tenant = $this->tenantContext->tenant();
        /** @var User $user */
        $user = $request->user();
        if ($tenant === null) {
            return ApiResponse::error('TENANT_REQUIRED', 'No active tenant context.', 400, null, $request);
        }

        /** @var Agent|null $agent */
        $agent = Agent::query()->where('tenant_id', $tenant->id)->where('id', $id)->first();
        if ($agent === null) {
            return ApiResponse::error('NOT_FOUND', 'Agent not found.', 404, null, $request);
        }

        $validated = $request->validate([
            'goal' => ['required', 'string', 'min:3', 'max:2000'],
            'report_title' => ['sometimes', 'string', 'min:3', 'max:200'],
            'knowledge_base_ids' => ['sometimes', 'array'],
            'knowledge_base_ids.*' => ['uuid'],
            'top_k' => ['sometimes', 'integer', 'min:1', 'max:12'],
            'skip_approval' => ['sometimes', 'boolean'],
            'approver_role' => ['sometimes', 'in:owner,admin,manager,member'],
            'tool_call' => ['sometimes', 'array'],
            'tool_call.name' => ['required_with:tool_call', 'string', 'max:120'],
            'tool_call.arguments' => ['sometimes', 'array'],
        ]);

        $run = $this->orchestrator->executeRun(
            tenant: $tenant,
            actor: $user,
            agent: $agent,
            goal: (string) $validated['goal'],
            input: $validated,
        );

        return ApiResponse::success($run->toApiArray(includeSteps: true, agentName: $agent->name), 201, $request);
    }

    public function listRuns(Request $request): JsonResponse
    {
        $tenant = $this->tenantContext->tenant();
        if ($tenant === null) {
            return ApiResponse::error('TENANT_REQUIRED', 'No active tenant context.', 400, null, $request);
        }

        $query = AgentRun::query()
            ->where('tenant_id', $tenant->id)
            ->orderByDesc('created_at');

        $agentId = $request->query('agent_id');
        if (is_string($agentId) && trim($agentId) !== '') {
            $query->where('agent_id', trim($agentId));
        }

        $status = $request->query('status');
        if (is_string($status) && trim($status) !== '') {
            $query->where('status', trim($status));
        }

        $runs = $query->limit(50)->get()
            ->map(static fn (AgentRun $run): array => $run->toApiArray(includeSteps: false))
            ->values()
            ->all();

        return ApiResponse::success($runs, 200, $request);
    }

    public function showRun(Request $request, string $runId): JsonResponse
    {
        $tenant = $this->tenantContext->tenant();
        if ($tenant === null) {
            return ApiResponse::error('TENANT_REQUIRED', 'No active tenant context.', 400, null, $request);
        }

        /** @var AgentRun|null $run */
        $run = AgentRun::query()->where('tenant_id', $tenant->id)->where('id', $runId)->first();
        if ($run === null) {
            return ApiResponse::error('NOT_FOUND', 'Agent run not found.', 404, null, $request);
        }

        return ApiResponse::success($run->toApiArray(includeSteps: true), 200, $request);
    }

    public function cancelRun(Request $request, string $runId): JsonResponse
    {
        $tenant = $this->tenantContext->tenant();
        /** @var User $user */
        $user = $request->user();
        if ($tenant === null) {
            return ApiResponse::error('TENANT_REQUIRED', 'No active tenant context.', 400, null, $request);
        }

        /** @var AgentRun|null $run */
        $run = AgentRun::query()->where('tenant_id', $tenant->id)->where('id', $runId)->first();
        if ($run === null) {
            return ApiResponse::error('NOT_FOUND', 'Agent run not found.', 404, null, $request);
        }

        $cancelled = $this->orchestrator->cancelRun($tenant, $user, $run);

        return ApiResponse::success($cancelled->toApiArray(includeSteps: true), 200, $request);
    }
}
