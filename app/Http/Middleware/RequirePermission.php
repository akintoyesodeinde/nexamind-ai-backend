<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Audit\Contracts\AuditLoggerInterface;
use App\Domain\Tenancy\Contracts\TenantContextInterface;
use App\Support\Http\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequirePermission
{
    public function __construct(
        private readonly TenantContextInterface $tenantContext,
        private readonly AuditLoggerInterface $auditLogger,
    ) {}

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        if (! $this->tenantContext->hasPermission($permission)) {
            $this->auditLogger->recordSecurityEvent(
                eventType: 'rbac.permission_denied',
                severity: 'medium',
                summary: "Permission '{$permission}' denied for role '{$this->tenantContext->roleSlug()}'",
                details: ['permission' => $permission, 'path' => $request->path()],
                tenantId: $this->tenantContext->tenantId(),
                actorUserId: is_string($request->user()?->id) ? $request->user()->id : null,
            );

            return ApiResponse::error(
                'FORBIDDEN',
                'You do not have permission to perform this action.',
                403,
                null,
                $request,
            );
        }

        return $next($request);
    }
}
