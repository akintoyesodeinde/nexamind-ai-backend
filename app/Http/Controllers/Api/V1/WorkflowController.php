<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Contracts\TenantContextInterface;
use App\Domain\Workflows\Models\WorkflowDefinition;
use App\Domain\Workflows\Models\WorkflowRun;
use App\Domain\Workflows\Services\WorkflowService;
use App\Http\Controllers\Controller;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class WorkflowController extends Controller
{
    public function __construct(
        private readonly TenantContextInterface $tenantContext,
        private readonly WorkflowService $workflowService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenant = $this->tenantContext->tenant();
        if ($tenant === null) {
            return ApiResponse::error('TENANT_REQUIRED', 'No active tenant context.', 400, null, $request);
        }

        $definitions = WorkflowDefinition::query()
            ->where('tenant_id', $tenant->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(static fn (WorkflowDefinition $def): array => $def->toApiArray())
            ->values()
            ->all();

        return ApiResponse::success($definitions, 200, $request);
    }

    public function seedComplianceTemplate(Request $request): JsonResponse
    {
        $tenant = $this->tenantContext->tenant();
        /** @var User $user */
        $user = $request->user();
        if ($tenant === null) {
            return ApiResponse::error('TENANT_REQUIRED', 'No active tenant context.', 400, null, $request);
        }

        $definition = $this->workflowService->ensureComplianceReportTemplate($tenant, $user);

        return ApiResponse::success($definition->toApiArray(), 201, $request);
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
            'workflow_type' => ['sometimes', 'string', 'in:compliance_report,policy_review,document_audit'],
            'agent_id' => ['nullable', 'uuid'],
            'approver_role' => ['sometimes', 'string', 'in:owner,admin,manager,member'],
            'steps_definition' => ['sometimes', 'array', 'min:1'],
        ]);

        $definition = $this->workflowService->createDefinition($tenant, $user, $validated);

        return ApiResponse::success($definition->toApiArray(), 201, $request);
    }

    public function launchRun(Request $request, string $id): JsonResponse
    {
        $tenant = $this->tenantContext->tenant();
        /** @var User $user */
        $user = $request->user();
        if ($tenant === null) {
            return ApiResponse::error('TENANT_REQUIRED', 'No active tenant context.', 400, null, $request);
        }

        /** @var WorkflowDefinition|null $definition */
        $definition = WorkflowDefinition::query()->where('tenant_id', $tenant->id)->where('id', $id)->first();
        if ($definition === null) {
            return ApiResponse::error('NOT_FOUND', 'Workflow definition not found.', 404, null, $request);
        }

        $validated = $request->validate([
            'goal' => ['sometimes', 'string', 'min:3', 'max:2000'],
            'report_title' => ['sometimes', 'string', 'min:3', 'max:200'],
            'knowledge_base_ids' => ['sometimes', 'array'],
            'knowledge_base_ids.*' => ['uuid'],
            'top_k' => ['sometimes', 'integer', 'min:1', 'max:12'],
        ]);

        $run = $this->workflowService->launchRun($tenant, $user, $definition, $validated);

        return ApiResponse::success($run->toApiArray(includeSteps: true, workflowName: $definition->name), 201, $request);
    }

    public function listRuns(Request $request): JsonResponse
    {
        $tenant = $this->tenantContext->tenant();
        if ($tenant === null) {
            return ApiResponse::error('TENANT_REQUIRED', 'No active tenant context.', 400, null, $request);
        }

        $query = WorkflowRun::query()
            ->where('tenant_id', $tenant->id)
            ->orderByDesc('created_at');

        $defId = $request->query('workflow_definition_id');
        if (is_string($defId) && trim($defId) !== '') {
            $query->where('workflow_definition_id', trim($defId));
        }

        $runs = $query->limit(50)->get()
            ->map(static fn (WorkflowRun $run): array => $run->toApiArray(includeSteps: true))
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

        /** @var WorkflowRun|null $run */
        $run = WorkflowRun::query()->where('tenant_id', $tenant->id)->where('id', $runId)->first();
        if ($run === null) {
            return ApiResponse::error('NOT_FOUND', 'Workflow run not found.', 404, null, $request);
        }

        return ApiResponse::success($run->toApiArray(includeSteps: true), 200, $request);
    }
}
