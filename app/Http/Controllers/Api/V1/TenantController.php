<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Documents\Models\Document;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Knowledge\Models\KnowledgeBase;
use App\Domain\Tenancy\Contracts\TenantContextInterface;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Tenancy\Models\TenantSetting;
use App\Domain\Tenancy\Services\TenantService;
use App\Http\Controllers\Controller;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TenantController extends Controller
{
    public function __construct(
        private readonly TenantService $tenantService,
        private readonly TenantContextInterface $tenantContext,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'slug' => ['nullable', 'string', 'min:2', 'max:80', 'regex:/^[a-z0-9-]+$/'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $result = $this->tenantService->createTenant(
            creator: $user,
            name: (string) $validated['name'],
            requestedSlug: isset($validated['slug']) ? (string) $validated['slug'] : null,
        );

        return ApiResponse::success([
            'tenant' => $result['tenant']->toApiArray(),
            'membership_id' => $result['membership']->id,
            'role' => $result['role']->slug,
        ], 201, $request);
    }

    public function current(Request $request): JsonResponse
    {
        $tenant = $this->tenantContext->tenant();
        if ($tenant === null) {
            return ApiResponse::error('TENANT_REQUIRED', 'No active tenant context.', 400, null, $request);
        }

        /** @var TenantSetting $settings */
        $settings = TenantSetting::query()->where('tenant_id', $tenant->id)->firstOrFail();

        $membersCount = TenantMembership::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('status', ['active', 'invited'])
            ->count();

        $auditLast24h = AuditLog::query()
            ->where('tenant_id', $tenant->id)
            ->where('created_at', '>=', now('UTC')->subDay())
            ->count();

        $kbCount = KnowledgeBase::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', 'active')
            ->count();

        $documentsCount = Document::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', '!=', 'deleted')
            ->count();

        return ApiResponse::success([
            'tenant' => $tenant->toApiArray(),
            'settings' => $settings->toApiArray(),
            'current_role' => $this->tenantContext->roleSlug(),
            'permissions' => $this->tenantContext->permissions(),
            'stats' => [
                'members_count' => $membersCount,
                'audit_events_last_24h' => $auditLast24h,
                'knowledge_bases_count' => $kbCount,
                'documents_count' => $documentsCount,
                'pending_approvals_count' => 0,
            ],
        ], 200, $request);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'default_chat_model' => ['sometimes', 'string', 'min:2', 'max:100'],
            'default_embedding_model' => ['sometimes', 'string', 'min:2', 'max:100'],
            'max_upload_mb' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'monthly_token_budget' => ['sometimes', 'integer', 'min:1000', 'max:500000000'],
            'monthly_cost_budget_usd' => ['sometimes', 'regex:/^\d+(\.\d{1,6})?$/'],
            'allowed_file_types' => ['sometimes', 'array', 'min:1'],
            'allowed_file_types.*' => ['string', 'in:pdf,docx,txt,md,csv'],
            'retention_days' => ['sometimes', 'integer', 'min:7', 'max:3650'],
            'require_approval_for_external_actions' => ['sometimes', 'boolean'],
        ]);

        $tenant = $this->tenantContext->tenant();
        /** @var User $user */
        $user = $request->user();
        if ($tenant === null) {
            return ApiResponse::error('TENANT_REQUIRED', 'No active tenant context.', 400, null, $request);
        }

        $settings = $this->tenantService->updateSettings($tenant, $user, $validated);

        return ApiResponse::success($settings->toApiArray(), 200, $request);
    }

    public function roles(Request $request): JsonResponse
    {
        $roles = Role::query()
            ->with('permissions')
            ->orderBy('name')
            ->get()
            ->map(static fn (Role $role): array => [
                'id' => $role->id,
                'slug' => $role->slug,
                'name' => $role->name,
                'description' => $role->description,
                'permissions' => $role->permissions->pluck('slug')->values()->all(),
            ])
            ->values()
            ->all();

        return ApiResponse::success($roles, 200, $request);
    }
}
