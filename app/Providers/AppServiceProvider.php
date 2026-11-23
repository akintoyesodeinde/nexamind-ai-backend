<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Audit\Contracts\AuditLoggerInterface;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Contracts\TenantContextInterface;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(TenantContextInterface::class, TenantContext::class);
        $this->app->scoped(AuditLoggerInterface::class, AuditLogger::class);
    }

    public function boot(): void
    {
        Gate::before(function (?User $user, string $ability): ?bool {
            if ($user === null) {
                return false;
            }

            /** @var TenantContextInterface $context */
            $context = app(TenantContextInterface::class);
            if (! $context->hasTenant()) {
                return null;
            }

            if (str_contains($ability, '.')) {
                return $context->hasPermission($ability);
            }

            return null;
        });

        RateLimiter::for('api', function (Request $request): Limit {
            $user = $request->user();
            $key = $user !== null ? (string) $user->id : (string) ($request->ip() ?? 'anonymous');

            return Limit::perMinute(120)->by($key);
        });

        RateLimiter::for('auth', function (Request $request): Limit {
            return Limit::perMinute(20)->by((string) ($request->ip() ?? 'anonymous'));
        });
    }
}
