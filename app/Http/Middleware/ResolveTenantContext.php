<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Audit\Contracts\AuditLoggerInterface;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Contracts\TenantContextInterface;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Tenancy\Models\UserRole;
use App\Support\Http\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class ResolveTenantContext
{
    public function __construct(
        private readonly TenantContextInterface $tenantContext,
        private readonly AuditLoggerInterface $auditLogger,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->tenantContext->clear();

        /** @var User|null $user */
        $user = $request->user();
        if ($user === null) {
            return ApiResponse::error('UNAUTHENTICATED', 'Authentication is required.', 401, null, $request);
        }

        $tenantId = $request->header('X-Tenant-ID');
        if (! is_string($tenantId) || ! Str::isUuid($tenantId)) {
            return ApiResponse::error(
                'TENANT_REQUIRED',
                'A valid X-Tenant-ID UUID header is required for tenant-scoped endpoints.',
                400,
                null,
                $request,
            );
        }

        /** @var Tenant|null $tenant */
        $tenant = Tenant::query()
            ->where('id', $tenantId)
            ->where('status', 'active')
            ->first();

        if ($tenant === null) {
            $this->auditLogger->recordSecurityEvent(
                eventType: 'tenancy.invalid_tenant_access',
                severity: 'medium',
                summary: 'Authenticated user requested unknown or inactive tenant',
                details: ['requested_tenant_id' => $tenantId, 'path' => $request->path()],
                tenantId: null,
                actorUserId: $user->id,
            );

            return ApiResponse::error('FORBIDDEN', 'Access to the requested organization is denied.', 403, null, $request);
        }

        /** @var TenantMembership|null $membership */
        $membership = TenantMembership::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->first();

        if ($membership === null) {
            $this->auditLogger->recordSecurityEvent(
                eventType: 'tenancy.cross_tenant_denied',
                severity: 'high',
                summary: 'Cross-tenant access attempt blocked by ResolveTenantContext',
                details: ['requested_tenant_id' => $tenant->id, 'path' => $request->path()],
                tenantId: $tenant->id,
                actorUserId: $user->id,
            );

            return ApiResponse::error('FORBIDDEN', 'Access to the requested organization is denied.', 403, null, $request);
        }

        /** @var UserRole|null $userRole */
        $userRole = UserRole::query()
            ->with('role.permissions')
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->first();

        $roleModel = $userRole?->role;
        $roleSlug = $roleModel !== null ? $roleModel->slug : 'viewer';
        /** @var list<string> $permissions */
        $permissions = $roleModel !== null
            ? $roleModel->permissions->pluck('slug')->values()->all()
            : [];

        $this->tenantContext->set($tenant, $membership, $roleSlug, $permissions);

        return $next($request);
    }
}
