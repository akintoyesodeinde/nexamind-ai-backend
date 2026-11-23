<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentChunk;
use App\Domain\Documents\Models\DocumentVersion;
use App\Domain\Documents\Services\DocumentService;
use App\Domain\Identity\Models\User;
use App\Domain\Knowledge\Models\KnowledgeBase;
use App\Domain\Tenancy\Contracts\TenantContextInterface;
use App\Http\Controllers\Controller;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

final class DocumentController extends Controller
{
    public function __construct(
        private readonly DocumentService $documentService,
        private readonly TenantContextInterface $tenantContext,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenant = $this->tenantContext->tenant();
        /** @var User $user */
        $user = $request->user();
        if ($tenant === null) {
            return ApiResponse::error('TENANT_REQUIRED', 'No active tenant context.', 400, null, $request);
        }

        $kbId = $request->query('knowledge_base_id');
        if (is_string($kbId) && $kbId !== '' && ! Str::isUuid($kbId)) {
            return ApiResponse::error('VALIDATION_ERROR', 'Invalid knowledge_base_id UUID.', 422, null, $request);
        }

        $status = $request->query('status');
        $search = $request->query('search');

        $documents = $this->documentService->listAuthorized(
            tenant: $tenant,
            user: $user,
            knowledgeBaseId: is_string($kbId) && $kbId !== '' ? $kbId : null,
            status: is_string($status) && $status !== '' ? $status : null,
            search: is_string($search) && $search !== '' ? $search : null,
        );

        return ApiResponse::success($documents, 200, $request);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'knowledge_base_id' => ['required', 'uuid'],
            'file' => ['required', 'file'],
            'access_policy' => ['sometimes', 'string', 'in:inherit,restricted'],
            'document_id' => ['sometimes', 'nullable', 'uuid'],
        ]);

        $tenant = $this->tenantContext->tenant();
        /** @var User $user */
        $user = $request->user();
        if ($tenant === null) {
            return ApiResponse::error('TENANT_REQUIRED', 'No active tenant context.', 400, null, $request);
        }

        /** @var KnowledgeBase|null $kb */
        $kb = KnowledgeBase::query()
            ->where('tenant_id', $tenant->id)
            ->where('id', (string) $validated['knowledge_base_id'])
            ->where('status', 'active')
            ->first();

        if ($kb === null) {
            return ApiResponse::error('NOT_FOUND', 'Knowledge base not found.', 404, null, $request);
        }

        $existing = null;
        if (! empty($validated['document_id'])) {
            $existing = $this->documentService->getAuthorizedOrFail($tenant, $user, (string) $validated['document_id']);
            if (strtolower($existing->knowledge_base_id) !== strtolower($kb->id)) {
                return ApiResponse::error('VALIDATION_ERROR', 'Document does not belong to the specified knowledge base.', 422, null, $request);
            }
        }

        /** @var UploadedFile $file */
        $file = $request->file('file');
        $result = $this->documentService->upload(
            tenant: $tenant,
            actor: $user,
            actorRoleSlug: $this->tenantContext->roleSlug() ?? 'viewer',
            knowledgeBase: $kb,
            file: $file,
            accessPolicy: isset($validated['access_policy']) ? (string) $validated['access_policy'] : 'inherit',
            existingDocument: $existing,
        );

        return ApiResponse::success([
            'document' => $result['document']->toApiArray(),
            'version' => $result['version']->toApiArray(),
        ], 201, $request);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        if (! Str::isUuid($id)) {
            return ApiResponse::error('NOT_FOUND', 'Document not found.', 404, null, $request);
        }

        $tenant = $this->tenantContext->tenant();
        /** @var User $user */
        $user = $request->user();
        if ($tenant === null) {
            return ApiResponse::error('TENANT_REQUIRED', 'No active tenant context.', 400, null, $request);
        }

        $document = $this->documentService->getAuthorizedOrFail($tenant, $user, $id);

        return ApiResponse::success($document->toApiArray(), 200, $request);
    }

    public function status(Request $request, string $id): JsonResponse
    {
        if (! Str::isUuid($id)) {
            return ApiResponse::error('NOT_FOUND', 'Document not found.', 404, null, $request);
        }

        $tenant = $this->tenantContext->tenant();
        /** @var User $user */
        $user = $request->user();
        if ($tenant === null) {
            return ApiResponse::error('TENANT_REQUIRED', 'No active tenant context.', 400, null, $request);
        }

        $document = $this->documentService->getAuthorizedOrFail($tenant, $user, $id);

        return ApiResponse::success([
            'id' => $document->id,
            'status' => $document->status,
            'current_version' => $document->current_version,
            'page_count' => $document->page_count,
            'chunk_count' => $document->chunk_count,
            'character_count' => $document->character_count,
            'error_message' => $document->error_message,
            'processed_at' => $document->processed_at?->toISOString(),
        ], 200, $request);
    }

    public function versions(Request $request, string $id): JsonResponse
    {
        if (! Str::isUuid($id)) {
            return ApiResponse::error('NOT_FOUND', 'Document not found.', 404, null, $request);
        }

        $tenant = $this->tenantContext->tenant();
        /** @var User $user */
        $user = $request->user();
        if ($tenant === null) {
            return ApiResponse::error('TENANT_REQUIRED', 'No active tenant context.', 400, null, $request);
        }

        $document = $this->documentService->getAuthorizedOrFail($tenant, $user, $id);

        $versions = DocumentVersion::query()
            ->where('tenant_id', $tenant->id)
            ->where('document_id', $document->id)
            ->orderByDesc('version_number')
            ->get()
            ->map(static fn (DocumentVersion $v): array => $v->toApiArray())
            ->values()
            ->all();

        return ApiResponse::success($versions, 200, $request);
    }

    public function chunks(Request $request, string $id): JsonResponse
    {
        if (! Str::isUuid($id)) {
            return ApiResponse::error('NOT_FOUND', 'Document not found.', 404, null, $request);
        }

        $tenant = $this->tenantContext->tenant();
        /** @var User $user */
        $user = $request->user();
        if ($tenant === null) {
            return ApiResponse::error('TENANT_REQUIRED', 'No active tenant context.', 400, null, $request);
        }

        $document = $this->documentService->getAuthorizedOrFail($tenant, $user, $id);

        $chunks = DocumentChunk::query()
            ->where('tenant_id', $tenant->id)
            ->where('document_id', $document->id)
            ->orderBy('chunk_index')
            ->get()
            ->map(static fn (DocumentChunk $c): array => $c->toApiArray())
            ->values()
            ->all();

        return ApiResponse::success($chunks, 200, $request);
    }

    public function downloadUrl(Request $request, string $id): JsonResponse
    {
        if (! Str::isUuid($id)) {
            return ApiResponse::error('NOT_FOUND', 'Document not found.', 404, null, $request);
        }

        $tenant = $this->tenantContext->tenant();
        /** @var User $user */
        $user = $request->user();
        if ($tenant === null) {
            return ApiResponse::error('TENANT_REQUIRED', 'No active tenant context.', 400, null, $request);
        }

        $document = $this->documentService->getAuthorizedOrFail($tenant, $user, $id);

        return ApiResponse::success($this->documentService->issueDownloadUrl($tenant, $user, $document), 200, $request);
    }

    public function permissions(Request $request, string $id): JsonResponse
    {
        if (! Str::isUuid($id)) {
            return ApiResponse::error('NOT_FOUND', 'Document not found.', 404, null, $request);
        }

        $tenant = $this->tenantContext->tenant();
        /** @var User $user */
        $user = $request->user();
        if ($tenant === null) {
            return ApiResponse::error('TENANT_REQUIRED', 'No active tenant context.', 400, null, $request);
        }

        $document = $this->documentService->getAuthorizedOrFail($tenant, $user, $id);

        return ApiResponse::success([
            'access_policy' => $document->access_policy,
            'grants' => $this->documentService->listPermissions($tenant, $document),
        ], 200, $request);
    }

    public function syncPermissions(Request $request, string $id): JsonResponse
    {
        if (! Str::isUuid($id)) {
            return ApiResponse::error('NOT_FOUND', 'Document not found.', 404, null, $request);
        }

        $validated = $request->validate([
            'access_policy' => ['required', 'string', 'in:inherit,restricted'],
            'grants' => ['required', 'array'],
            'grants.*.principal_type' => ['required', 'string', 'in:user,role'],
            'grants.*.principal_id' => ['required', 'uuid'],
            'grants.*.permission_level' => ['required', 'string', 'in:read,write,admin,deny'],
        ]);

        $tenant = $this->tenantContext->tenant();
        /** @var User $user */
        $user = $request->user();
        if ($tenant === null) {
            return ApiResponse::error('TENANT_REQUIRED', 'No active tenant context.', 400, null, $request);
        }

        /** @var Document|null $document */
        $document = Document::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->where('status', '!=', 'deleted')
            ->first();

        if ($document === null) {
            return ApiResponse::error('NOT_FOUND', 'Document not found.', 404, null, $request);
        }

        /** @var list<array{principal_type: string, principal_id: string, permission_level: string}> $grants */
        $grants = $validated['grants'];

        $updatedGrants = $this->documentService->syncPermissions(
            tenant: $tenant,
            actor: $user,
            actorRoleSlug: $this->tenantContext->roleSlug() ?? 'viewer',
            document: $document,
            accessPolicy: (string) $validated['access_policy'],
            grants: $grants,
        );

        return ApiResponse::success([
            'access_policy' => (string) $validated['access_policy'],
            'grants' => $updatedGrants,
        ], 200, $request);
    }

    public function destroy(Request $request, string $id): Response|JsonResponse
    {
        if (! Str::isUuid($id)) {
            return ApiResponse::error('NOT_FOUND', 'Document not found.', 404, null, $request);
        }

        $tenant = $this->tenantContext->tenant();
        /** @var User $user */
        $user = $request->user();
        if ($tenant === null) {
            return ApiResponse::error('TENANT_REQUIRED', 'No active tenant context.', 400, null, $request);
        }

        $document = $this->documentService->getAuthorizedOrFail($tenant, $user, $id);
        $this->documentService->deleteDocument($tenant, $user, $this->tenantContext->roleSlug() ?? 'viewer', $document);

        return response()->noContent();
    }
}
