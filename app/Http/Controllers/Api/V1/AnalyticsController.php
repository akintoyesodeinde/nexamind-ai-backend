<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Analytics\Services\AnalyticsService;
use App\Domain\Tenancy\Contracts\TenantContextInterface;
use App\Http\Controllers\Controller;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AnalyticsController extends Controller
{
    public function __construct(
        private readonly TenantContextInterface $tenantContext,
        private readonly AnalyticsService $analyticsService,
    ) {}

    public function overview(Request $request): JsonResponse
    {
        $tenant = $this->tenantContext->tenant();
        if ($tenant === null) {
            return ApiResponse::error('TENANT_REQUIRED', 'No active tenant context.', 400, null, $request);
        }

        $days = min(max((int) $request->integer('days', 30), 1), 365);

        return ApiResponse::success(
            $this->analyticsService->overview($tenant, $days),
            200,
            $request,
        );
    }

    public function usage(Request $request): JsonResponse
    {
        $tenant = $this->tenantContext->tenant();
        if ($tenant === null) {
            return ApiResponse::error('TENANT_REQUIRED', 'No active tenant context.', 400, null, $request);
        }

        $days = min(max((int) $request->integer('days', 30), 1), 365);

        return ApiResponse::success(
            $this->analyticsService->usageBreakdown($tenant, $days),
            200,
            $request,
        );
    }
}
