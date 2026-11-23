<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Contracts;

use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;

interface TenantContextInterface
{
    /**
     * @param  list<string>  $permissions
     */
    public function set(
        Tenant $tenant,
        TenantMembership $membership,
        string $roleSlug,
        array $permissions,
    ): void;

    public function clear(): void;

    public function hasTenant(): bool;

    public function tenant(): ?Tenant;

    public function tenantId(): ?string;

    public function membership(): ?TenantMembership;

    public function roleSlug(): ?string;

    /**
     * @return list<string>
     */
    public function permissions(): array;

    public function hasPermission(string $permission): bool;
}
