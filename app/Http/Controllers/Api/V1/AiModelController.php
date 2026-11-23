<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\AI\Services\ModelGatewayService;
use App\Domain\Tenancy\Contracts\TenantContextInterface;
use App\Http\Controllers\Controller;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AiModelController extends Controller
{
    public function __construct(
        private readonly ModelGatewayService $modelGateway,
        private readonly TenantContextInterface $tenantContext,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenant = $this->tenantContext->tenant();
        if ($tenant === null) {
            return ApiResponse::error('TENANT_REQUIRED', 'No active tenant context.', 400, null, $request);
        }

        $capability = $request->query('capability');
        $resolvedCap = is_string($capability) && in_array($capability, ['chat', 'embedding'], true)
            ? $capability
            : null;

        return ApiResponse::success(
            $this->modelGateway->listAvailableModels($tenant, $resolvedCap),
            200,
            $request,
        );
    }
}
