<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Contracts\TenantContextInterface;
use App\Domain\Workflows\Models\ApprovalRequest;
use App\Domain\Workflows\Services\ApprovalService;
use App\Http\Controllers\Controller;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ApprovalController extends Controller
{
    public function __construct(
        private readonly TenantContextInterface $tenantContext,
        private readonly ApprovalService $approvalService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenant = $this->tenantContext->tenant();
        if ($tenant === null) {
            return ApiResponse::error('TENANT_REQUIRED', 'No active tenant context.', 400, null, $request);
        }

        $query = ApprovalRequest::query()
            ->where('tenant_id', $tenant->id)
            ->orderByDesc('created_at');

        $status = $request->query('status');
        if (is_string($status) && in_array($status, ['pending', 'approved', 'rejected', 'cancelled'], true)) {
            $query->where('status', $status);
        }

        $items = $query->limit(50)->get()
            ->map(static fn (ApprovalRequest $req): array => $req->toApiArray())
            ->values()
            ->all();

        return ApiResponse::success($items, 200, $request);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $tenant = $this->tenantContext->tenant();
        if ($tenant === null) {
            return ApiResponse::error('TENANT_REQUIRED', 'No active tenant context.', 400, null, $request);
        }

        /** @var ApprovalRequest|null $approval */
        $approval = ApprovalRequest::query()->where('tenant_id', $tenant->id)->where('id', $id)->first();
        if ($approval === null) {
            return ApiResponse::error('NOT_FOUND', 'Approval request not found.', 404, null, $request);
        }

        return ApiResponse::success($approval->toApiArray(), 200, $request);
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        $tenant = $this->tenantContext->tenant();
        /** @var User $user */
        $user = $request->user();
        if ($tenant === null) {
            return ApiResponse::error('TENANT_REQUIRED', 'No active tenant context.', 400, null, $request);
        }

        /** @var ApprovalRequest|null $approval */
        $approval = ApprovalRequest::query()->where('tenant_id', $tenant->id)->where('id', $id)->first();
        if ($approval === null) {
            return ApiResponse::error('NOT_FOUND', 'Approval request not found.', 404, null, $request);
        }

        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:950'],
        ]);

        $result = $this->approvalService->approve(
            tenant: $tenant,
            actor: $user,
            approval: $approval,
            note: isset($validated['note']) ? (string) $validated['note'] : null,
        );

        return ApiResponse::success([
            'approval' => $result['approval']->toApiArray(),
            'already_decided' => $result['already_decided'],
            'published_artifact' => $result['published_artifact'],
        ], 200, $request);
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        $tenant = $this->tenantContext->tenant();
        /** @var User $user */
        $user = $request->user();
        if ($tenant === null) {
            return ApiResponse::error('TENANT_REQUIRED', 'No active tenant context.', 400, null, $request);
        }

        /** @var ApprovalRequest|null $approval */
        $approval = ApprovalRequest::query()->where('tenant_id', $tenant->id)->where('id', $id)->first();
        if ($approval === null) {
            return ApiResponse::error('NOT_FOUND', 'Approval request not found.', 404, null, $request);
        }

        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:950'],
        ]);

        $result = $this->approvalService->reject(
            tenant: $tenant,
            actor: $user,
            approval: $approval,
            note: isset($validated['note']) ? (string) $validated['note'] : null,
        );

        return ApiResponse::success([
            'approval' => $result['approval']->toApiArray(),
            'already_decided' => $result['already_decided'],
        ], 200, $request);
    }
}
