<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Concerns;

use App\Domain\Tenancy\Contracts\TenantContextInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Automatically enforces tenant_id isolation whenever a TenantContext is active.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant_scope', function (Builder $builder): void {
            /** @var TenantContextInterface $context */
            $context = app(TenantContextInterface::class);
            if ($context->hasTenant()) {
                $builder->where(
                    $builder->qualifyColumn('tenant_id'),
                    '=',
                    $context->tenantId(),
                );
            }
        });

        static::creating(function (Model $model): void {
            /** @var TenantContextInterface $context */
            $context = app(TenantContextInterface::class);
            if ($context->hasTenant() && empty($model->getAttribute('tenant_id'))) {
                $model->setAttribute('tenant_id', $context->tenantId());
            }
        });
    }
}
