<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Tenancy\Services\TenantContext;
use PHPUnit\Framework\TestCase;

final class TenantContextUnitTest extends TestCase
{
    public function test_tenant_context_sets_and_clears_permissions(): void
    {
        $ctx = new TenantContext;
        $this->assertFalse($ctx->hasTenant());
        $this->assertFalse($ctx->hasPermission('users.manage'));

        $tenant = new Tenant;
        $tenant->id = '11111111-1111-4111-8111-111111111111';
        $membership = new TenantMembership;
        $membership->id = '22222222-2222-4222-8222-222222222222';

        $ctx->set($tenant, $membership, 'admin', ['users.manage', 'audit.view', 'users.manage']);

        $this->assertTrue($ctx->hasTenant());
        $this->assertSame('11111111-1111-4111-8111-111111111111', $ctx->tenantId());
        $this->assertSame('admin', $ctx->roleSlug());
        $this->assertTrue($ctx->hasPermission('users.manage'));
        $this->assertFalse($ctx->hasPermission('tenant.manage'));
        $this->assertCount(2, $ctx->permissions());

        $ctx->clear();
        $this->assertFalse($ctx->hasTenant());
        $this->assertNull($ctx->tenantId());
    }
}
