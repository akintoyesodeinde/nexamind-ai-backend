<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Identity\Models\User;
use App\Domain\Retrieval\Services\RetrievalService;
use App\Domain\Tenancy\Contracts\TenantContextInterface;
use App\Http\Controllers\Controller;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class RetrievalController extends Controller
{
    public function __construct(
        private readonly RetrievalService $retrievalService,
        private readonly TenantContextInterface $tenantContext,
    ) {}

    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'query' => ['required', 'string', 'min:2', 'max:2000'],
            'knowledge_base_ids' => ['sometimes', 'array'],
            'knowledge_base_ids.*' => ['uuid'],
            'top_k' => ['sometimes', 'integer', 'min:1', 'max:25'],
            'min_score' => ['sometimes', 'numeric', 'min:0', 'max:1'],
        ]);

        $tenant = $this->tenantContext->tenant();
        /** @var User $user */
        $user = $request->user();
        if ($tenant === null) {
            return ApiResponse::error('TENANT_REQUIRED', 'No active tenant context.', 400, null, $request);
        }

        /** @var list<string>|null $kbIds */
        $kbIds = isset($validated['knowledge_base_ids']) ? array_values($validated['knowledge_base_ids']) : null;

        $payload = $this->retrievalService->search(
            tenant: $tenant,
            user: $user,
            queryText: (string) $validated['query'],
            knowledgeBaseIds: $kbIds,
            topK: isset($validated['top_k']) ? (int) $validated['top_k'] : 8,
            minScore: isset($validated['min_score']) ? (float) $validated['min_score'] : 0.0,
        );

        return ApiResponse::success($payload, 200, $request);
    }
}
