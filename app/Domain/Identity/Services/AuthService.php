<?php

declare(strict_types=1);

namespace App\Domain\Identity\Services;

use App\Domain\Audit\Contracts\AuditLoggerInterface;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Tenancy\Models\UserRole;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

final class AuthService
{
    public function __construct(
        private readonly AuditLoggerInterface $auditLogger,
    ) {}

    /**
     * @param  array{name: string, email: string, password: string, job_title?: string|null}  $data
     */
    public function register(array $data): User
    {
        $user = User::query()->create([
            'name' => trim($data['name']),
            'email' => strtolower(trim($data['email'])),
            'password' => $data['password'],
            'job_title' => isset($data['job_title']) && $data['job_title'] !== '' ? trim($data['job_title']) : null,
            'timezone' => 'UTC',
            'locale' => 'en-US',
            'preferences_json' => ['citations_expanded_by_default' => true],
            'status' => 'active',
            'last_login_at' => now('UTC'),
        ]);

        $this->auditLogger->record(
            action: 'identity.user.registered',
            resourceType: 'user',
            resourceId: $user->id,
            metadata: ['email' => $user->email],
            tenantId: null,
            actorUserId: $user->id,
        );

        return $user;
    }

    public function verifyCredentials(string $email, string $password): User
    {
        $normalizedEmail = strtolower(trim($email));
        /** @var User|null $user */
        $user = User::query()->where('email', $normalizedEmail)->first();

        if ($user === null || ! Hash::check($password, $user->password)) {
            $this->auditLogger->recordSecurityEvent(
                eventType: 'auth.login_failed',
                severity: 'low',
                summary: 'Invalid login credentials supplied',
                details: ['email' => $normalizedEmail],
                tenantId: null,
                actorUserId: $user?->id,
            );

            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if ($user->status !== 'active') {
            $this->auditLogger->recordSecurityEvent(
                eventType: 'auth.suspended_login_blocked',
                severity: 'medium',
                summary: 'Suspended user attempted authentication',
                details: ['email' => $normalizedEmail],
                tenantId: null,
                actorUserId: $user->id,
            );

            throw ValidationException::withMessages([
                'email' => ['This account is suspended. Contact your administrator.'],
            ]);
        }

        $user->forceFill(['last_login_at' => now('UTC')])->save();

        $this->auditLogger->record(
            action: 'identity.user.logged_in',
            resourceType: 'user',
            resourceId: $user->id,
            metadata: [],
            tenantId: null,
            actorUserId: $user->id,
        );

        return $user;
    }

    /**
     * @return array{user: array<string, mixed>, memberships: list<array<string, mixed>>}
     */
    public function buildMePayload(User $user): array
    {
        $memberships = TenantMembership::query()
            ->with('tenant')
            ->where('user_id', $user->id)
            ->whereIn('status', ['active', 'invited'])
            ->orderBy('created_at')
            ->get();

        $userRoles = UserRole::query()
            ->with(['role.permissions'])
            ->where('user_id', $user->id)
            ->get()
            ->groupBy('tenant_id');

        $serializedMemberships = [];
        foreach ($memberships as $membership) {
            if ($membership->tenant === null || $membership->tenant->status !== 'active') {
                continue;
            }

            /** @var UserRole|null $primaryUserRole */
            $primaryUserRole = $userRoles->get($membership->tenant_id)?->first();
            /** @var Role|null $role */
            $role = $primaryUserRole?->role;

            $permissions = [];
            if ($role !== null) {
                $permissions = $role->permissions->pluck('slug')->values()->all();
            }

            $serializedMemberships[] = [
                'membership_id' => $membership->id,
                'tenant' => $membership->tenant->toApiArray(),
                'role' => $role !== null ? $role->slug : 'viewer',
                'role_name' => $role !== null ? $role->name : 'Viewer',
                'status' => $membership->status,
                'permissions' => $permissions,
                'joined_at' => $membership->joined_at?->toISOString(),
            ];
        }

        return [
            'user' => $user->toApiArray(),
            'memberships' => $serializedMemberships,
        ];
    }

    /**
     * @param  array{name: string, job_title?: string|null, timezone: string, locale: string, preferences?: array<string, mixed>|null}  $data
     */
    public function updateProfile(User $user, array $data): User
    {
        $user->fill([
            'name' => trim($data['name']),
            'job_title' => array_key_exists('job_title', $data) && $data['job_title'] !== null && $data['job_title'] !== ''
                ? trim($data['job_title'])
                : null,
            'timezone' => $data['timezone'],
            'locale' => $data['locale'],
            'preferences_json' => array_key_exists('preferences', $data) && is_array($data['preferences'])
                ? $data['preferences']
                : ($user->preferences_json ?? []),
        ]);
        $user->save();

        $this->auditLogger->record(
            action: 'identity.profile.updated',
            resourceType: 'user',
            resourceId: $user->id,
            metadata: ['timezone' => $user->timezone, 'locale' => $user->locale],
            tenantId: null,
            actorUserId: $user->id,
        );

        return $user->refresh();
    }
}
