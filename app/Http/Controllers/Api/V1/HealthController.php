<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

final class HealthController extends Controller
{
    public function live(Request $request): JsonResponse
    {
        return ApiResponse::success([
            'status' => 'ok',
            'service' => 'nexamind-ai-backend',
            'php_version' => PHP_VERSION,
        ], 200, $request);
    }

    public function ready(Request $request): JsonResponse
    {
        try {
            DB::selectOne('SELECT 1 AS ok');

            return ApiResponse::success([
                'status' => 'ready',
                'database' => 'connected',
                'driver' => config('database.default'),
            ], 200, $request);
        } catch (Throwable $e) {
            return ApiResponse::error(
                'DEPENDENCY_UNAVAILABLE',
                'Database readiness check failed.',
                503,
                ['database' => [$e->getMessage()]],
                $request,
            );
        }
    }
}
