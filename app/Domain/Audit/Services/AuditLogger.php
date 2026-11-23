<?php

declare(strict_types=1);

namespace App\Domain\Audit\Services;

use App\Domain\Audit\Contracts\AuditLoggerInterface;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Audit\Models\SecurityEvent;
use App\Domain\Tenancy\Contracts\TenantContextInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class AuditLogger implements AuditLoggerInterface
{
    private const GENESIS_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    public function __construct(
        private readonly TenantContextInterface $tenantContext,
    ) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        string $action,
        string $resourceType,
        ?string $resourceId = null,
        array $metadata = [],
        ?string $tenantId = null,
        ?string $actorUserId = null,
    ): AuditLog {
        $resolvedTenantId = $tenantId ?? $this->tenantContext->tenantId();
        $resolvedActorId = $actorUserId ?? (is_string(Auth::id()) ? Auth::id() : null);
        $req = request();
        $requestId = $req->attributes->get('request_id');
        $requestIdStr = is_string($requestId) ? $requestId : null;

        /** @var AuditLog $entry */
        $entry = DB::transaction(function () use (
            $resolvedTenantId,
            $resolvedActorId,
            $action,
            $resourceType,
            $resourceId,
            $requestIdStr,
            $req,
            $metadata,
        ): AuditLog {
            $lastQuery = AuditLog::query()->orderByDesc('created_at')->orderByDesc('id');
            if ($resolvedTenantId !== null) {
                $lastQuery->where('tenant_id', $resolvedTenantId);
            } else {
                $lastQuery->whereNull('tenant_id');
            }

            /** @var AuditLog|null $previous */
            $previous = $lastQuery->first();
            $previousHash = $previous !== null ? $previous->record_hash : self::GENESIS_HASH;

            $id = (string) Str::uuid();
            $createdAt = now('UTC');
            $recordHash = $this->computeHash(
                id: $id,
                tenantId: $resolvedTenantId,
                actorUserId: $resolvedActorId,
                action: $action,
                resourceType: $resourceType,
                resourceId: $resourceId,
                metadata: $metadata,
                previousHash: $previousHash,
            );

            $log = new AuditLog([
                'tenant_id' => $resolvedTenantId,
                'actor_user_id' => $resolvedActorId,
                'action' => $action,
                'resource_type' => $resourceType,
                'resource_id' => $resourceId,
                'request_id' => $requestIdStr,
                'ip_address' => $req->ip(),
                'user_agent' => $req->userAgent() ? Str::limit((string) $req->userAgent(), 500, '') : null,
                'metadata_json' => $metadata,
                'previous_hash' => $previousHash,
                'record_hash' => $recordHash,
            ]);
            $log->id = $id;
            $log->created_at = $createdAt;
            $log->save();

            return $log;
        });

        Log::info('audit.recorded', [
            'request_id' => $requestIdStr,
            'tenant_id' => $resolvedTenantId,
            'actor_user_id' => $resolvedActorId,
            'action' => $action,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
        ]);

        return $entry;
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public function recordSecurityEvent(
        string $eventType,
        string $severity,
        string $summary,
        array $details = [],
        ?string $tenantId = null,
        ?string $actorUserId = null,
    ): SecurityEvent {
        $resolvedTenantId = $tenantId ?? $this->tenantContext->tenantId();
        $resolvedActorId = $actorUserId ?? (is_string(Auth::id()) ? Auth::id() : null);
        $req = request();
        $requestId = $req->attributes->get('request_id');
        $requestIdStr = is_string($requestId) ? $requestId : null;

        $event = SecurityEvent::query()->create([
            'tenant_id' => $resolvedTenantId,
            'actor_user_id' => $resolvedActorId,
            'event_type' => $eventType,
            'severity' => $severity,
            'request_id' => $requestIdStr,
            'ip_address' => $req->ip(),
            'summary' => Str::limit($summary, 255, ''),
            'details_json' => $details,
        ]);

        Log::warning('security.event', [
            'request_id' => $requestIdStr,
            'tenant_id' => $resolvedTenantId,
            'actor_user_id' => $resolvedActorId,
            'event_type' => $eventType,
            'severity' => $severity,
            'summary' => $summary,
        ]);

        return $event;
    }

    public function verifyTenantChain(string $tenantId): bool
    {
        $entries = AuditLog::query()
            ->where('tenant_id', $tenantId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $expectedPrev = self::GENESIS_HASH;
        foreach ($entries as $entry) {
            if ($entry->previous_hash !== $expectedPrev) {
                return false;
            }

            $recomputed = $this->computeHash(
                id: $entry->id,
                tenantId: $entry->tenant_id,
                actorUserId: $entry->actor_user_id,
                action: $entry->action,
                resourceType: $entry->resource_type,
                resourceId: $entry->resource_id,
                metadata: $entry->metadata_json ?? [],
                previousHash: $entry->previous_hash,
            );

            if (! hash_equals($entry->record_hash, $recomputed)) {
                return false;
            }

            $expectedPrev = $entry->record_hash;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function computeHash(
        string $id,
        ?string $tenantId,
        ?string $actorUserId,
        string $action,
        string $resourceType,
        ?string $resourceId,
        array $metadata,
        string $previousHash,
    ): string {
        $canonical = json_encode([
            'id' => strtolower($id),
            'tenant_id' => $tenantId !== null ? strtolower($tenantId) : null,
            'actor_user_id' => $actorUserId !== null ? strtolower($actorUserId) : null,
            'action' => $action,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'metadata' => $metadata,
            'previous_hash' => $previousHash,
        ], JSON_THROW_ON_ERROR);

        return hash('sha256', $canonical);
    }
}
