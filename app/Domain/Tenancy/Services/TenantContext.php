<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Services;

use App\Domain\Tenancy\Contracts\TenantContextInterface;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;

final class TenantContext implements TenantContextInterface
{
    private ?Tenant $tenant = null;

    private ?TenantMembership $membership = null;

    private ?string $roleSlug = null;

    /**
     * @var list<string>
     */
    private array $permissions = [];

    /**
     * @param  list<string>  $permissions
     */
    public function set(
        Tenant $tenant,
        TenantMembership $membership,
        string $roleSlug,
        array $permissions,
    ): void {
        $this->tenant = $tenant;
        $this->membership = $membership;
        $this->roleSlug = $roleSlug;
        $this->permissions = array_values(array_unique($permissions));
    }

    public function clear(): void
    {
        $this->tenant = null;
        $this->membership = null;
        $this->roleSlug = null;
        $this->permissions = [];
    }

    public function hasTenant(): bool
    {
        return $this->tenant !== null;
    }

    public function tenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function tenantId(): ?string
    {
        return $this->tenant?->id;
    }

    public function membership(): ?TenantMembership
    {
        return $this->membership;
    }

    public function roleSlug(): ?string
    {
        return $this->roleSlug;
    }

    public function permissions(): array
    {
        return $this->permissions;
    }

    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }
}
