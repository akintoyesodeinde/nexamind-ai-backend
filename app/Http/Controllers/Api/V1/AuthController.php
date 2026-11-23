<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\Contracts\AuditLoggerInterface;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AuthService;
use App\Http\Controllers\Controller;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Laravel\Sanctum\Contracts\HasAbilities;
use Laravel\Sanctum\PersonalAccessToken;

final class AuthController extends Controller
{
    public function __construct(
        private readonly AuthService $authService,
        private readonly AuditLoggerInterface $auditLogger,
    ) {}

    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:255', 'unique:users,email'],
            'job_title' => ['nullable', 'string', 'max:120'],
            'password' => ['required', 'string', 'confirmed', Password::min(10)->mixedCase()->numbers()],
        ]);

        $user = $this->authService->register([
            'name' => (string) $validated['name'],
            'email' => (string) $validated['email'],
            'password' => (string) $validated['password'],
            'job_title' => isset($validated['job_title']) ? (string) $validated['job_title'] : null,
        ]);

        if ($request->hasSession()) {
            Auth::guard('web')->login($user);
            $request->session()->regenerate();
        }

        $payload = $this->authService->buildMePayload($user);
        if ($request->header('X-Client-Mode') === 'api-token') {
            $payload['token'] = $user->createToken('api-client')->plainTextToken;
        }

        return ApiResponse::success($payload, 201, $request);
    }

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ]);

        $user = $this->authService->verifyCredentials(
            (string) $validated['email'],
            (string) $validated['password'],
        );

        if ($request->hasSession()) {
            Auth::guard('web')->login($user, (bool) ($validated['remember'] ?? false));
            $request->session()->regenerate();
        }

        $payload = $this->authService->buildMePayload($user);
        if ($request->header('X-Client-Mode') === 'api-token') {
            $payload['token'] = $user->createToken('api-client')->plainTextToken;
        }

        return ApiResponse::success($payload, 200, $request);
    }

    public function logout(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user !== null) {
            /** @var HasAbilities|null $token */
            $token = $user->currentAccessToken();
            if ($token instanceof PersonalAccessToken) {
                $token->delete();
            }

            $this->auditLogger->record(
                action: 'identity.user.logged_out',
                resourceType: 'user',
                resourceId: $user->id,
                metadata: [],
                tenantId: null,
                actorUserId: $user->id,
            );
        }

        if ($request->hasSession()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return ApiResponse::success(['logged_out' => true], 200, $request);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return ApiResponse::success($this->authService->buildMePayload($user), 200, $request);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'job_title' => ['nullable', 'string', 'max:120'],
            'timezone' => ['required', 'string', 'timezone:all'],
            'locale' => ['required', 'string', 'min:2', 'max:16'],
            'preferences' => ['sometimes', 'array'],
            'preferences.density' => ['sometimes', 'in:comfortable,compact'],
            'preferences.citations_expanded_by_default' => ['sometimes', 'boolean'],
            'preferences.email_approvals_digest' => ['sometimes', 'boolean'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $updated = $this->authService->updateProfile($user, $validated);

        return ApiResponse::success($updated->toApiArray(), 200, $request);
    }
}
