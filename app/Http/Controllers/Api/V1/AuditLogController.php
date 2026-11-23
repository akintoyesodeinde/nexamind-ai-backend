<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\Contracts\AuditLoggerInterface;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Audit\Models\SecurityEvent;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Contracts\TenantContextInterface;
use App\Http\Controllers\Controller;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AuditLogController extends Controller
{
    public function __construct(
        private readonly TenantContextInterface $tenantContext,
        private readonly AuditLoggerInterface $auditLogger,
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
            $query->whereIn('resource_type', ['knowledge_base', 'document', 'agent', 'workflow', 'approval_request', 'evaluation']);
        }

        $actionFilter = $request->query('action');
        if (is_string($actionFilter) && trim($actionFilter) !== '') {
            $query->where('action', 'like', '%'.trim($actionFilter).'%');
        }

        $resourceType = $request->query('resource_type');
        if (is_string($resourceType) && trim($resourceType) !== '') {
            $query->where('resource_type', trim($resourceType));
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
            'record_hash_prefix' => substr($log->record_hash, 0, 12),
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

    public function securityEvents(Request $request): JsonResponse
    {
        $tenantId = $this->tenantContext->tenantId();
        if ($tenantId === null) {
            return ApiResponse::error('TENANT_REQUIRED', 'No active tenant context.', 400, null, $request);
        }

        $perPage = min(max((int) $request->integer('per_page', 25), 1), 100);
        $query = SecurityEvent::query()
            ->where('tenant_id', $tenantId)
            ->orderByDesc('created_at');

        $severity = $request->query('severity');
        if (is_string($severity) && in_array($severity, ['low', 'medium', 'high', 'critical'], true)) {
            $query->where('severity', $severity);
        }

        $eventType = $request->query('event_type');
        if (is_string($eventType) && trim($eventType) !== '') {
            $query->where('event_type', 'like', '%'.trim($eventType).'%');
        }

        $page = $query->paginate($perPage);
        $actorIds = collect($page->items())
            ->map(static fn (SecurityEvent $e): ?string => $e->actor_user_id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        $actors = [];
        if ($actorIds !== []) {
            foreach (User::query()->whereIn('id', $actorIds)->get() as $u) {
                $actors[strtolower($u->id)] = $u;
            }
        }

        $items = collect($page->items())->map(static function (SecurityEvent $event) use ($actors): array {
            $actor = $event->actor_user_id !== null ? ($actors[strtolower($event->actor_user_id)] ?? null) : null;

            return [
                'id' => $event->id,
                'event_type' => $event->event_type,
                'severity' => $event->severity,
                'summary' => $event->summary,
                'actor_email' => $actor?->email,
                'actor_name' => $actor?->name,
                'request_id' => $event->request_id,
                'ip_address' => $event->ip_address,
                'details' => $event->details_json ?? [],
                'created_at' => $event->created_at->toISOString(),
            ];
        })->values()->all();

        return ApiResponse::success($items, 200, $request, [
            'pagination' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    public function verifyChain(Request $request): JsonResponse
    {
        $tenantId = $this->tenantContext->tenantId();
        if ($tenantId === null) {
            return ApiResponse::error('TENANT_REQUIRED', 'No active tenant context.', 400, null, $request);
        }

        $valid = $this->auditLogger->verifyTenantChain($tenantId);
        $totalRecords = AuditLog::query()->where('tenant_id', $tenantId)->count();

        return ApiResponse::success([
            'tenant_id' => $tenantId,
            'chain_valid' => $valid,
            'records_verified' => $totalRecords,
            'verified_at' => now('UTC')->toISOString(),
        ], 200, $request);
    }
}
