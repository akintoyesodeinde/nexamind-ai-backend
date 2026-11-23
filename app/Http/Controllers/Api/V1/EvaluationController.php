<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Analytics\Models\EvaluationDataset;
use App\Domain\Analytics\Models\EvaluationRun;
use App\Domain\Analytics\Services\EvaluationService;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Contracts\TenantContextInterface;
use App\Http\Controllers\Controller;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class EvaluationController extends Controller
{
    public function __construct(
        private readonly TenantContextInterface $tenantContext,
        private readonly EvaluationService $evaluationService,
    ) {}

    public function listDatasets(Request $request): JsonResponse
    {
        $tenant = $this->tenantContext->tenant();
        if ($tenant === null) {
            return ApiResponse::error('TENANT_REQUIRED', 'No active tenant context.', 400, null, $request);
        }

        $datasets = EvaluationDataset::query()
            ->where('tenant_id', $tenant->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(static fn (EvaluationDataset $d): array => $d->toApiArray(includeCases: true))
            ->values()
            ->all();

        return ApiResponse::success($datasets, 200, $request);
    }

    public function storeDataset(Request $request): JsonResponse
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
            'knowledge_base_ids' => ['sometimes', 'array'],
            'knowledge_base_ids.*' => ['uuid'],
            'cases' => ['required', 'array', 'min:1', 'max:50'],
            'cases.*.category' => ['sometimes', 'string', 'max:60'],
            'cases.*.question' => ['required', 'string', 'min:3', 'max:1000'],
            'cases.*.expected_keywords' => ['sometimes', 'array'],
            'cases.*.expected_keywords.*' => ['string', 'max:100'],
            'cases.*.expect_abstention' => ['sometimes', 'boolean'],
            'cases.*.require_citations' => ['sometimes', 'boolean'],
        ]);

        $dataset = $this->evaluationService->createDataset($tenant, $user, $validated);

        return ApiResponse::success($dataset->toApiArray(includeCases: true), 201, $request);
    }

    public function seedBenchmark(Request $request): JsonResponse
    {
        $tenant = $this->tenantContext->tenant();
        /** @var User $user */
        $user = $request->user();
        if ($tenant === null) {
            return ApiResponse::error('TENANT_REQUIRED', 'No active tenant context.', 400, null, $request);
        }

        $validated = $request->validate([
            'knowledge_base_ids' => ['sometimes', 'array'],
            'knowledge_base_ids.*' => ['uuid'],
        ]);

        /** @var list<string> $kbIds */
        $kbIds = isset($validated['knowledge_base_ids']) && is_array($validated['knowledge_base_ids'])
            ? array_values(array_map(static fn (mixed $v): string => (string) $v, $validated['knowledge_base_ids']))
            : [];

        $dataset = $this->evaluationService->seedCuratedBenchmark($tenant, $user, $kbIds);

        return ApiResponse::success($dataset->toApiArray(includeCases: true), 201, $request);
    }

    public function runEvaluation(Request $request): JsonResponse
    {
        $tenant = $this->tenantContext->tenant();
        /** @var User $user */
        $user = $request->user();
        if ($tenant === null) {
            return ApiResponse::error('TENANT_REQUIRED', 'No active tenant context.', 400, null, $request);
        }

        $validated = $request->validate([
            'dataset_id' => ['required', 'uuid'],
            'model_alias' => ['sometimes', 'string', 'max:100'],
        ]);

        /** @var EvaluationDataset|null $dataset */
        $dataset = EvaluationDataset::query()
            ->where('tenant_id', $tenant->id)
            ->where('id', (string) $validated['dataset_id'])
            ->first();

        if ($dataset === null) {
            return ApiResponse::error('NOT_FOUND', 'Evaluation dataset not found.', 404, null, $request);
        }

        $run = $this->evaluationService->runEvaluation(
            tenant: $tenant,
            actor: $user,
            dataset: $dataset,
            modelAlias: isset($validated['model_alias']) ? (string) $validated['model_alias'] : null,
        );

        return ApiResponse::success($run->toApiArray(includeResults: true), 201, $request);
    }

    public function listRuns(Request $request): JsonResponse
    {
        $tenant = $this->tenantContext->tenant();
        if ($tenant === null) {
            return ApiResponse::error('TENANT_REQUIRED', 'No active tenant context.', 400, null, $request);
        }

        $runs = EvaluationRun::query()
            ->where('tenant_id', $tenant->id)
            ->orderByDesc('created_at')
            ->limit(30)
            ->get()
            ->map(static fn (EvaluationRun $r): array => $r->toApiArray(includeResults: false))
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

        /** @var EvaluationRun|null $run */
        $run = EvaluationRun::query()->where('tenant_id', $tenant->id)->where('id', $runId)->first();
        if ($run === null) {
            return ApiResponse::error('NOT_FOUND', 'Evaluation run not found.', 404, null, $request);
        }

        return ApiResponse::success($run->toApiArray(includeResults: true), 200, $request);
    }
}
