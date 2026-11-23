<?php

declare(strict_types=1);

namespace App\Support\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class ApiResponse
{
    /**
     * @param  array<string, mixed>  $extraMeta
     */
    public static function success(
        mixed $data,
        int $status = 200,
        ?Request $request = null,
        array $extraMeta = [],
    ): JsonResponse {
        $requestId = self::resolveRequestId($request);

        return response()->json([
            'data' => $data,
            'meta' => array_merge([
                'request_id' => $requestId,
                'timestamp' => now('UTC')->toISOString(),
            ], $extraMeta),
        ], $status, ['X-Request-ID' => $requestId]);
    }

    /**
     * @param  array<string, mixed>|null  $details
     */
    public static function error(
        string $code,
        string $message,
        int $status,
        ?array $details = null,
        ?Request $request = null,
    ): JsonResponse {
        $requestId = self::resolveRequestId($request);

        $error = [
            'code' => $code,
            'message' => $message,
        ];

        if ($details !== null && $details !== []) {
            $error['details'] = $details;
        }

        return response()->json([
            'error' => $error,
            'meta' => [
                'request_id' => $requestId,
                'timestamp' => now('UTC')->toISOString(),
            ],
        ], $status, ['X-Request-ID' => $requestId]);
    }

    private static function resolveRequestId(?Request $request): string
    {
        $req = $request ?? request();
        $existing = $req->attributes->get('request_id');
        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        $generated = (string) Str::uuid();
        $req->attributes->set('request_id', $generated);

        return $generated;
    }
}
