<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\TenantController;
use App\Http\Controllers\Api\V1\TenantMemberController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    // Public liveness and readiness probes
    Route::get('/health/live', [HealthController::class, 'live']);
    Route::get('/health/ready', [HealthController::class, 'ready']);

    // Public auth endpoints (rate-limited)
    Route::middleware('throttle:auth')->group(function (): void {
        Route::post('/auth/register', [AuthController::class, 'register']);
        Route::post('/auth/login', [AuthController::class, 'login']);
    });

    // Authenticated user-level endpoints (pre-tenant & cross-org)
    Route::middleware(['auth:sanctum', 'throttle:api'])->group(function (): void {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
        Route::patch('/me/profile', [AuthController::class, 'updateProfile']);
        Route::post('/tenants', [TenantController::class, 'store']);
        Route::get('/roles', [TenantController::class, 'roles']);

        // Strictly tenant-scoped endpoints (requires valid active membership via X-Tenant-ID)
        Route::middleware(['tenant.context', 'idempotency'])->group(function (): void {
            Route::get('/tenants/current', [TenantController::class, 'current']);

            Route::patch('/tenants/current/settings', [TenantController::class, 'updateSettings'])
                ->middleware('permission:tenant.settings.manage');

            Route::get('/tenants/current/members', [TenantMemberController::class, 'index'])
                ->middleware('permission:users.manage');
            Route::post('/tenants/current/members', [TenantMemberController::class, 'store'])
                ->middleware('permission:users.manage');
            Route::patch('/tenants/current/members/{membershipId}', [TenantMemberController::class, 'update'])
                ->middleware('permission:users.manage');
            Route::delete('/tenants/current/members/{membershipId}', [TenantMemberController::class, 'destroy'])
                ->middleware('permission:users.manage');

            Route::get('/audit-logs', [AuditLogController::class, 'index'])
                ->middleware('permission:audit.view');
        });
    });
});
