<?php

declare(strict_types=1);

namespace App\Domain\Audit\Contracts;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Audit\Models\SecurityEvent;

interface AuditLoggerInterface
{
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
    ): AuditLog;

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
    ): SecurityEvent;

    public function verifyTenantChain(string $tenantId): bool;
}
