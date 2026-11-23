<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Documents\Services\DocumentIntelligenceService;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Contracts\TenantContextInterface;
use App\Http\Controllers\Controller;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class DocumentIntelligenceController extends Controller
{
    public function __construct(
        private readonly DocumentIntelligenceService $intelligenceService,
        private readonly TenantContextInterface $tenantContext,
    ) {}

    public function analyze(Request $request, string $id): JsonResponse
    {
        if (! Str::isUuid($id)) {
            return ApiResponse::error('NOT_FOUND', 'Document not found.', 404, null, $request);
        }

        $validated = $request->validate([
            'action' => ['required', 'string', 'in:summarize,classify,extract_structured'],
            'schema_fields' => ['sometimes', 'array', 'max:12'],
            'schema_fields.*' => ['string', 'min:1', 'max:60'],
            'model' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        $tenant = $this->tenantContext->tenant();
        /** @var User $user */
        $user = $request->user();
        if ($tenant === null) {
            return ApiResponse::error('TENANT_REQUIRED', 'No active tenant context.', 400, null, $request);
        }

        /** @var list<string>|null $fields */
        $fields = isset($validated['schema_fields']) ? array_values($validated['schema_fields']) : null;

        $result = $this->intelligenceService->analyze(
            tenant: $tenant,
            user: $user,
            documentId: $id,
            action: (string) $validated['action'],
            schemaFields: $fields,
            modelAlias: isset($validated['model']) ? (string) $validated['model'] : null,
        );

        return ApiResponse::success($result, 200, $request);
    }

    public function compare(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'document_a_id' => ['required', 'uuid'],
            'document_b_id' => ['required', 'uuid', 'different:document_a_id'],
            'focus' => ['sometimes', 'nullable', 'string', 'max:400'],
            'model' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        $tenant = $this->tenantContext->tenant();
        /** @var User $user */
        $user = $request->user();
        if ($tenant === null) {
            return ApiResponse::error('TENANT_REQUIRED', 'No active tenant context.', 400, null, $request);
        }

        $result = $this->intelligenceService->compare(
            tenant: $tenant,
            user: $user,
            documentAId: (string) $validated['document_a_id'],
            documentBId: (string) $validated['document_b_id'],
            focus: isset($validated['focus']) ? (string) $validated['focus'] : null,
            modelAlias: isset($validated['model']) ? (string) $validated['model'] : null,
        );

        return ApiResponse::success($result, 200, $request);
    }
}
