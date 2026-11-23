<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Contracts\TenantContextInterface;
use App\Domain\Tenancy\Services\TenantService;
use App\Http\Controllers\Controller;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

final class TenantMemberController extends Controller
{
    public function __construct(
        private readonly TenantService $tenantService,
        private readonly TenantContextInterface $tenantContext,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenant = $this->tenantContext->tenant();
        if ($tenant === null) {
            return ApiResponse::error('TENANT_REQUIRED', 'No active tenant context.', 400, null, $request);
        }

        return ApiResponse::success($this->tenantService->listMembers($tenant), 200, $request);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'name' => ['nullable', 'string', 'max:120'],
            'role_slug' => ['required', 'string', 'in:owner,admin,manager,member,viewer'],
        ]);

        $tenant = $this->tenantContext->tenant();
        /** @var User $actor */
        $actor = $request->user();
        if ($tenant === null) {
            return ApiResponse::error('TENANT_REQUIRED', 'No active tenant context.', 400, null, $request);
        }

        $member = $this->tenantService->addMember(
            tenant: $tenant,
            actor: $actor,
            actorRoleSlug: $this->tenantContext->roleSlug() ?? 'viewer',
            email: (string) $validated['email'],
            name: isset($validated['name']) ? (string) $validated['name'] : null,
            roleSlug: (string) $validated['role_slug'],
        );

        return ApiResponse::success($member, 201, $request);
    }

    public function update(Request $request, string $membershipId): JsonResponse
    {
        if (! Str::isUuid($membershipId)) {
            return ApiResponse::error('NOT_FOUND', 'Membership not found.', 404, null, $request);
        }

        $validated = $request->validate([
            'role_slug' => ['sometimes', 'string', 'in:owner,admin,manager,member,viewer'],
            'status' => ['sometimes', 'string', 'in:active,invited,suspended'],
        ]);

        $tenant = $this->tenantContext->tenant();
        /** @var User $actor */
        $actor = $request->user();
        if ($tenant === null) {
            return ApiResponse::error('TENANT_REQUIRED', 'No active tenant context.', 400, null, $request);
        }

        $updated = $this->tenantService->updateMember(
            tenant: $tenant,
            actor: $actor,
            actorRoleSlug: $this->tenantContext->roleSlug() ?? 'viewer',
            membershipId: $membershipId,
            newRoleSlug: isset($validated['role_slug']) ? (string) $validated['role_slug'] : null,
            newStatus: isset($validated['status']) ? (string) $validated['status'] : null,
        );

        return ApiResponse::success($updated, 200, $request);
    }

    public function destroy(Request $request, string $membershipId): Response|JsonResponse
    {
        if (! Str::isUuid($membershipId)) {
            return ApiResponse::error('NOT_FOUND', 'Membership not found.', 404, null, $request);
        }

        $tenant = $this->tenantContext->tenant();
        /** @var User $actor */
        $actor = $request->user();
        if ($tenant === null) {
            return ApiResponse::error('TENANT_REQUIRED', 'No active tenant context.', 400, null, $request);
        }

        $this->tenantService->removeMember(
            tenant: $tenant,
            actor: $actor,
            actorRoleSlug: $this->tenantContext->roleSlug() ?? 'viewer',
            membershipId: $membershipId,
        );

        return response()->noContent();
    }
}
