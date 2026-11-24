<?php

declare(strict_types=1);

use App\Domain\Agents\Exceptions\ToolSecurityException;
use App\Domain\AI\Exceptions\QuotaExceededException;
use App\Http\Middleware\IdempotencyMiddleware;
use App\Http\Middleware\RequestIdMiddleware;
use App\Http\Middleware\RequirePermission;
use App\Http\Middleware\ResolveTenantContext;
use App\Support\Http\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: ['127.0.0.1', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16']);
        $middleware->statefulApi();
        $middleware->append(RequestIdMiddleware::class);
        $middleware->alias([
            'tenant.context' => ResolveTenantContext::class,
            'permission' => RequirePermission::class,
            'idempotency' => IdempotencyMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            static fn (Request $request): bool => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (ValidationException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error(
                'VALIDATION_ERROR',
                $e->getMessage() ?: 'The given data was invalid.',
                422,
                $e->errors(),
                $request,
            );
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error('UNAUTHENTICATED', 'Authentication is required.', 401, null, $request);
        });

        $exceptions->render(function (AuthorizationException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error(
                'FORBIDDEN',
                $e->getMessage() ?: 'You are not authorized to perform this action.',
                403,
                null,
                $request,
            );
        });

        $exceptions->render(function (ModelNotFoundException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error('NOT_FOUND', 'The requested resource was not found.', 404, null, $request);
        });

        $exceptions->render(function (QuotaExceededException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error('QUOTA_EXCEEDED', $e->getMessage(), 429, $e->quotaDetails, $request);
        });

        $exceptions->render(function (ToolSecurityException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error($e->errorCode, $e->getMessage(), $e->httpStatus, $e->details, $request);
        });

        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error('RATE_LIMITED', 'Too many requests. Please retry later.', 429, null, $request);
        });

        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $status = $e->getStatusCode();
            $code = match ($status) {
                403 => 'FORBIDDEN',
                404 => 'NOT_FOUND',
                409 => 'CONFLICT',
                429 => 'RATE_LIMITED',
                default => 'HTTP_ERROR',
            };

            return ApiResponse::error(
                $code,
                $e->getMessage() ?: 'Request could not be completed.',
                $status,
                null,
                $request,
            );
        });
    })->create();
