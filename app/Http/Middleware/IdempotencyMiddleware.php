<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Tenancy\Contracts\TenantContextInterface;
use App\Support\Http\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class IdempotencyMiddleware
{
    public function __construct(
        private readonly TenantContextInterface $tenantContext,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');
        if (! is_string($key) || trim($key) === '') {
            return $next($request);
        }

        $tenantId = $this->tenantContext->tenantId();
        $userId = $request->user()?->id;
        if ($tenantId === null || ! is_string($userId)) {
            return $next($request);
        }

        $key = Str::limit(trim($key), 128, '');
        $method = strtoupper($request->method());
        $path = '/'.ltrim($request->path(), '/');
        $requestHash = hash('sha256', $request->getContent());

        /** @var object{request_hash: string, response_status: int, response_body_json: string}|null $existing */
        $existing = DB::table('api_idempotency_keys')
            ->where('tenant_id', $tenantId)
            ->where('idempotency_key', $key)
            ->where('request_method', $method)
            ->where('request_path', $path)
            ->where('expires_at', '>', now('UTC'))
            ->first();

        if ($existing !== null) {
            if (! hash_equals($existing->request_hash, $requestHash)) {
                return ApiResponse::error(
                    'IDEMPOTENCY_CONFLICT',
                    'Idempotency-Key was already used with a different request payload.',
                    409,
                    null,
                    $request,
                );
            }

            return response($existing->response_body_json, (int) $existing->response_status, [
                'Content-Type' => 'application/json',
                'X-Idempotency-Replayed' => 'true',
            ]);
        }

        /** @var Response $response */
        $response = $next($request);

        if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
            DB::table('api_idempotency_keys')->insert([
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'idempotency_key' => $key,
                'request_method' => $method,
                'request_path' => $path,
                'request_hash' => $requestHash,
                'response_status' => $response->getStatusCode(),
                'response_body_json' => (string) $response->getContent(),
                'expires_at' => now('UTC')->addHours(24),
                'created_at' => now('UTC'),
            ]);
        }

        return $response;
    }
}
