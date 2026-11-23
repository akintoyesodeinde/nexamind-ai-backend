<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Identity\Models\User;
use App\Domain\Knowledge\Models\KnowledgeBase;
use App\Domain\Knowledge\Services\AuthorizedScopeResolver;
use App\Domain\Knowledge\Services\KnowledgeBaseService;
use App\Domain\Tenancy\Contracts\TenantContextInterface;
use App\Http\Controllers\Controller;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class KnowledgeBaseController extends Controller
{
    public function __construct(
        private readonly KnowledgeBaseService $kbService,
        private readonly AuthorizedScopeResolver $scopeResolver,
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

        return ApiResponse::success($this->kbService->listPermitted($tenant, $user), 200, $request);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'slug' => ['nullable', 'string', 'min:2', 'max:100', 'regex:/^[a-z0-9-]+$/'],
            'description' => ['nullable', 'string', 'max:1000'],
            'visibility' => ['sometimes', 'string', 'in:tenant,restricted'],
        ]);

        $tenant = $this->tenantContext->tenant();
        /** @var User $user */
        $user = $request->user();
        if ($tenant === null) {
            return ApiResponse::error('TENANT_REQUIRED', 'No active tenant context.', 400, null, $request);
        }

        $kb = $this->kbService->create($tenant, $user, [
            'name' => (string) $validated['name'],
            'slug' => isset($validated['slug']) ? (string) $validated['slug'] : null,
            'description' => isset($validated['description']) ? (string) $validated['description'] : null,
            'visibility' => isset($validated['visibility']) ? (string) $validated['visibility'] : 'restricted',
        ]);

        return ApiResponse::success($kb->toApiArray(0), 201, $request);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        if (! Str::isUuid($id)) {
            return ApiResponse::error('NOT_FOUND', 'Knowledge base not found.', 404, null, $request);
        }

        $tenant = $this->tenantContext->tenant();
        /** @var User $user */
        $user = $request->user();
        if ($tenant === null) {
            return ApiResponse::error('TENANT_REQUIRED', 'No active tenant context.', 400, null, $request);
        }

        /** @var KnowledgeBase|null $kb */
        $kb = KnowledgeBase::query()
            ->where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->first();

        if ($kb === null) {
            return ApiResponse::error('NOT_FOUND', 'Knowledge base not found.', 404, null, $request);
        }

        if (! $this->scopeResolver->canReadKnowledgeBase($tenant->id, $user->id, $kb->id)) {
            return ApiResponse::error('FORBIDDEN', 'Access to this knowledge base is denied.', 403, null, $request);
        }

        return ApiResponse::success($kb->toApiArray(), 200, $request);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        if (! Str::isUuid($id)) {
            return ApiResponse::error('NOT_FOUND', 'Knowledge base not found.', 404, null, $request);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'visibility' => ['sometimes', 'string', 'in:tenant,restricted'],
            'status' => ['sometimes', 'string', 'in:active,archived'],
        ]);

        $tenant = $this->tenantContext->tenant();
        /** @var User $user */
        $user = $request->user();
        if ($tenant === null) {
            return ApiResponse::error('TENANT_REQUIRED', 'No active tenant context.', 400, null, $request);
        }

        /** @var KnowledgeBase|null $kb */
        $kb = KnowledgeBase::query()->where('tenant_id', $tenant->id)->where('id', $id)->first();
        if ($kb === null) {
            return ApiResponse::error('NOT_FOUND', 'Knowledge base not found.', 404, null, $request);
        }

        if (! $this->scopeResolver->canManageKnowledgeBase($tenant->id, $user->id, $kb->id, $this->tenantContext->roleSlug())) {
            return ApiResponse::error('FORBIDDEN', 'Insufficient permissions to modify this knowledge base.', 403, null, $request);
        }

        $updated = $this->kbService->update($tenant, $user, $kb, $validated);

        return ApiResponse::success($updated->toApiArray(), 200, $request);
    }

    public function permissions(Request $request, string $id): JsonResponse
    {
        if (! Str::isUuid($id)) {
            return ApiResponse::error('NOT_FOUND', 'Knowledge base not found.', 404, null, $request);
        }

        $tenant = $this->tenantContext->tenant();
        /** @var User $user */
        $user = $request->user();
        if ($tenant === null) {
            return ApiResponse::error('TENANT_REQUIRED', 'No active tenant context.', 400, null, $request);
        }

        /** @var KnowledgeBase|null $kb */
        $kb = KnowledgeBase::query()->where('tenant_id', $tenant->id)->where('id', $id)->first();
        if ($kb === null) {
            return ApiResponse::error('NOT_FOUND', 'Knowledge base not found.', 404, null, $request);
        }

        if (! $this->scopeResolver->canManageKnowledgeBase($tenant->id, $user->id, $kb->id, $this->tenantContext->roleSlug())) {
            return ApiResponse::error('FORBIDDEN', 'Insufficient permissions to view knowledge base ACLs.', 403, null, $request);
        }

        return ApiResponse::success($this->kbService->listPermissions($tenant, $kb), 200, $request);
    }

    public function syncPermissions(Request $request, string $id): JsonResponse
    {
        if (! Str::isUuid($id)) {
            return ApiResponse::error('NOT_FOUND', 'Knowledge base not found.', 404, null, $request);
        }

        $validated = $request->validate([
            'grants' => ['required', 'array'],
            'grants.*.principal_type' => ['required', 'string', 'in:user,role'],
            'grants.*.principal_id' => ['required', 'uuid'],
            'grants.*.permission_level' => ['required', 'string', 'in:read,write,admin'],
        ]);

        $tenant = $this->tenantContext->tenant();
        /** @var User $user */
        $user = $request->user();
        if ($tenant === null) {
            return ApiResponse::error('TENANT_REQUIRED', 'No active tenant context.', 400, null, $request);
        }

        /** @var KnowledgeBase|null $kb */
        $kb = KnowledgeBase::query()->where('tenant_id', $tenant->id)->where('id', $id)->first();
        if ($kb === null) {
            return ApiResponse::error('NOT_FOUND', 'Knowledge base not found.', 404, null, $request);
        }

        if (! $this->scopeResolver->canManageKnowledgeBase($tenant->id, $user->id, $kb->id, $this->tenantContext->roleSlug())) {
            return ApiResponse::error('FORBIDDEN', 'Insufficient permissions to update knowledge base ACLs.', 403, null, $request);
        }

        /** @var list<array{principal_type: string, principal_id: string, permission_level: string}> $grants */
        $grants = $validated['grants'];

        return ApiResponse::success($this->kbService->syncPermissions($tenant, $user, $kb, $grants), 200, $request);
    }
}
