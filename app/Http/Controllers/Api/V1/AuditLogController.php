<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Tenancy\Contracts\TenantContextInterface;
use App\Http\Controllers\Controller;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AuditLogController extends Controller
{
    public function __construct(
        private readonly TenantContextInterface $tenantContext,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->tenantContext->tenantId();
        if ($tenantId === null) {
            return ApiResponse::error('TENANT_REQUIRED', 'No active tenant context.', 400, null, $request);
        }

        $perPage = min(max((int) $request->integer('per_page', 25), 1), 100);
        $query = AuditLog::query()
            ->with('actor')
            ->where('tenant_id', $tenantId)
            ->orderByDesc('created_at');

        if ($this->tenantContext->roleSlug() === 'manager') {
            $query->whereIn('resource_type', ['knowledge_base', 'document', 'agent', 'workflow', 'approval_request']);
        }

        $actionFilter = $request->query('action');
        if (is_string($actionFilter) && trim($actionFilter) !== '') {
            $query->where('action', 'like', '%'.trim($actionFilter).'%');
        }

        $page = $query->paginate($perPage);

        $items = collect($page->items())->map(static fn (AuditLog $log): array => [
            'id' => $log->id,
            'action' => $log->action,
            'resource_type' => $log->resource_type,
            'resource_id' => $log->resource_id,
            'actor_email' => $log->actor?->email,
            'actor_name' => $log->actor?->name,
            'request_id' => $log->request_id,
            'ip_address' => $log->ip_address,
            'metadata' => $log->metadata_json ?? [],
            'created_at' => $log->created_at->toISOString(),
        ])->values()->all();

        return ApiResponse::success($items, 200, $request, [
            'pagination' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }
}
