<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Services;

use App\Domain\Audit\Contracts\AuditLoggerInterface;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Tenancy\Models\TenantSetting;
use App\Domain\Tenancy\Models\UserRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class TenantService
{
    public function __construct(
        private readonly AuditLoggerInterface $auditLogger,
    ) {}

    /**
     * @return array{tenant: Tenant, membership: TenantMembership, role: Role}
     */
    public function createTenant(User $creator, string $name, ?string $requestedSlug = null): array
    {
        $slug = $requestedSlug !== null && trim($requestedSlug) !== ''
            ? Str::slug($requestedSlug)
            : Str::slug($name);

        if ($slug === '') {
            $slug = 'org-'.Str::lower(Str::random(6));
        }

        if (Tenant::query()->where('slug', $slug)->exists()) {
            throw ValidationException::withMessages([
                'slug' => ['This organization slug is already in use.'],
            ]);
        }

        /** @var Role $ownerRole */
        $ownerRole = Role::query()->where('slug', 'owner')->firstOrFail();

        /** @var array{tenant: Tenant, membership: TenantMembership, role: Role} $result */
        $result = DB::transaction(function () use ($creator, $name, $slug, $ownerRole): array {
            $tenant = Tenant::query()->create([
                'name' => trim($name),
                'slug' => $slug,
                'plan' => 'sandbox',
                'status' => 'active',
                'created_by' => $creator->id,
            ]);

            TenantSetting::query()->create([
                'tenant_id' => $tenant->id,
                'default_chat_model' => 'default-chat',
                'default_embedding_model' => 'default-embedding',
                'max_upload_mb' => 20,
                'monthly_token_budget' => 500000,
                'monthly_cost_budget_usd' => '25.000000',
                'allowed_file_types_json' => ['pdf', 'docx', 'txt', 'md', 'csv'],
                'retention_days' => 365,
                'require_approval_for_external_actions' => true,
            ]);

            $membership = TenantMembership::query()->create([
                'tenant_id' => $tenant->id,
                'user_id' => $creator->id,
                'status' => 'active',
                'invited_by' => $creator->id,
                'joined_at' => now('UTC'),
            ]);

            UserRole::query()->create([
                'tenant_id' => $tenant->id,
                'user_id' => $creator->id,
                'role_id' => $ownerRole->id,
                'assigned_by' => $creator->id,
            ]);

            return [
                'tenant' => $tenant,
                'membership' => $membership,
                'role' => $ownerRole,
            ];
        });

        $this->auditLogger->record(
            action: 'tenancy.tenant.created',
            resourceType: 'tenant',
            resourceId: $result['tenant']->id,
            metadata: ['slug' => $result['tenant']->slug],
            tenantId: $result['tenant']->id,
            actorUserId: $creator->id,
        );

        return $result;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateSettings(Tenant $tenant, User $actor, array $attributes): TenantSetting
    {
        /** @var TenantSetting $settings */
        $settings = TenantSetting::query()->where('tenant_id', $tenant->id)->firstOrFail();

        $allowed = [
            'default_chat_model',
            'default_embedding_model',
            'max_upload_mb',
            'monthly_token_budget',
            'monthly_cost_budget_usd',
            'retention_days',
            'require_approval_for_external_actions',
        ];

        $updates = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $attributes)) {
                $updates[$key] = $attributes[$key];
            }
        }
        if (array_key_exists('allowed_file_types', $attributes) && is_array($attributes['allowed_file_types'])) {
            $updates['allowed_file_types_json'] = array_values($attributes['allowed_file_types']);
        }

        $settings->fill($updates);
        $settings->save();

        $this->auditLogger->record(
            action: 'tenancy.settings.updated',
            resourceType: 'tenant_settings',
            resourceId: $settings->id,
            metadata: ['updated_keys' => array_keys($updates)],
            tenantId: $tenant->id,
            actorUserId: $actor->id,
        );

        return $settings->refresh();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listMembers(Tenant $tenant): array
    {
        $memberships = TenantMembership::query()
            ->with('user')
            ->where('tenant_id', $tenant->id)
            ->orderBy('created_at')
            ->get();

        $rolesByUser = UserRole::query()
            ->with('role')
            ->where('tenant_id', $tenant->id)
            ->get()
            ->keyBy('user_id');

        $rows = [];
        foreach ($memberships as $membership) {
            if ($membership->user === null) {
                continue;
            }
            /** @var UserRole|null $ur */
            $ur = $rolesByUser->get($membership->user_id);
            $roleModel = $ur?->role;

            $rows[] = [
                'id' => $membership->id,
                'user_id' => $membership->user_id,
                'name' => $membership->user->name,
                'email' => $membership->user->email,
                'job_title' => $membership->user->job_title,
                'role' => $roleModel !== null ? $roleModel->slug : 'viewer',
                'role_name' => $roleModel !== null ? $roleModel->name : 'Viewer',
                'status' => $membership->status,
                'joined_at' => $membership->joined_at?->toISOString(),
                'created_at' => $membership->created_at->toISOString(),
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    public function addMember(
        Tenant $tenant,
        User $actor,
        string $actorRoleSlug,
        string $email,
        ?string $name,
        string $roleSlug,
    ): array {
        if ($roleSlug === 'owner' && $actorRoleSlug !== 'owner') {
            $this->auditLogger->recordSecurityEvent(
                eventType: 'rbac.privilege_escalation_blocked',
                severity: 'high',
                summary: 'Non-owner attempted to assign Owner role',
                details: ['actor_role' => $actorRoleSlug, 'target_email' => $email],
                tenantId: $tenant->id,
                actorUserId: $actor->id,
            );

            abort(403, 'Only an Owner can assign the Owner role.');
        }

        /** @var Role $role */
        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();
        $normalizedEmail = strtolower(trim($email));

        /** @var array{membership: TenantMembership, user: User, role: Role} $created */
        $created = DB::transaction(function () use ($tenant, $actor, $normalizedEmail, $name, $role): array {
            /** @var User|null $targetUser */
            $targetUser = User::query()->where('email', $normalizedEmail)->first();
            $status = 'active';
            $joinedAt = now('UTC');

            if ($targetUser === null) {
                $targetUser = User::query()->create([
                    'name' => ($name !== null && trim($name) !== '') ? trim($name) : Str::before($normalizedEmail, '@'),
                    'email' => $normalizedEmail,
                    'password' => Str::password(24),
                    'timezone' => 'UTC',
                    'locale' => 'en-US',
                    'preferences_json' => ['citations_expanded_by_default' => true],
                    'status' => 'active',
                ]);
                $status = 'invited';
                $joinedAt = null;
            }

            if (TenantMembership::query()
                ->where('tenant_id', $tenant->id)
                ->where('user_id', $targetUser->id)
                ->exists()) {
                throw ValidationException::withMessages([
                    'email' => ['This user is already a member of the organization.'],
                ]);
            }

            $membership = TenantMembership::query()->create([
                'tenant_id' => $tenant->id,
                'user_id' => $targetUser->id,
                'status' => $status,
                'invited_by' => $actor->id,
                'joined_at' => $joinedAt,
            ]);

            UserRole::query()->create([
                'tenant_id' => $tenant->id,
                'user_id' => $targetUser->id,
                'role_id' => $role->id,
                'assigned_by' => $actor->id,
            ]);

            return [
                'membership' => $membership,
                'user' => $targetUser,
                'role' => $role,
            ];
        });

        $this->auditLogger->record(
            action: 'tenancy.member.added',
            resourceType: 'tenant_membership',
            resourceId: $created['membership']->id,
            metadata: ['email' => $created['user']->email, 'role' => $created['role']->slug],
            tenantId: $tenant->id,
            actorUserId: $actor->id,
        );

        return [
            'id' => $created['membership']->id,
            'user_id' => $created['user']->id,
            'name' => $created['user']->name,
            'email' => $created['user']->email,
            'job_title' => $created['user']->job_title,
            'role' => $created['role']->slug,
            'role_name' => $created['role']->name,
            'status' => $created['membership']->status,
            'joined_at' => $created['membership']->joined_at?->toISOString(),
            'created_at' => $created['membership']->created_at->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function updateMember(
        Tenant $tenant,
        User $actor,
        string $actorRoleSlug,
        string $membershipId,
        ?string $newRoleSlug,
        ?string $newStatus,
    ): array {
        /** @var TenantMembership $membership */
        $membership = TenantMembership::query()
            ->with('user')
            ->where('tenant_id', $tenant->id)
            ->where('id', $membershipId)
            ->firstOrFail();

        /** @var UserRole|null $currentUserRole */
        $currentUserRole = UserRole::query()
            ->with('role')
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $membership->user_id)
            ->first();

        $currentRoleModel = $currentUserRole?->role;
        $currentRoleSlug = $currentRoleModel !== null ? $currentRoleModel->slug : 'viewer';

        if (($currentRoleSlug === 'owner' || $newRoleSlug === 'owner') && $actorRoleSlug !== 'owner') {
            $this->auditLogger->recordSecurityEvent(
                eventType: 'rbac.privilege_escalation_blocked',
                severity: 'high',
                summary: 'Non-owner attempted to modify or assign Owner role',
                details: ['actor_role' => $actorRoleSlug, 'membership_id' => $membershipId],
                tenantId: $tenant->id,
                actorUserId: $actor->id,
            );

            abort(403, 'Only an Owner can assign or modify an Owner role.');
        }

        if ($currentRoleSlug === 'owner' && ($newRoleSlug !== null && $newRoleSlug !== 'owner' || $newStatus === 'suspended')) {
            $ownerCount = $this->countActiveOwners($tenant->id);
            if ($ownerCount <= 1) {
                throw ValidationException::withMessages([
                    'role_slug' => ['Organization must retain at least one active Owner.'],
                ]);
            }
        }

        /** @var Role $resolvedRole */
        $resolvedRole = $newRoleSlug !== null
            ? Role::query()->where('slug', $newRoleSlug)->firstOrFail()
            : Role::query()->where('slug', $currentRoleSlug)->firstOrFail();

        DB::transaction(function () use ($tenant, $actor, $membership, $newRoleSlug, $newStatus, $resolvedRole): void {
            if ($newStatus !== null) {
                $membership->status = $newStatus;
                if ($newStatus === 'active' && $membership->joined_at === null) {
                    $membership->joined_at = now('UTC');
                }
                $membership->save();
            }

            if ($newRoleSlug !== null) {
                UserRole::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('user_id', $membership->user_id)
                    ->delete();

                UserRole::query()->create([
                    'tenant_id' => $tenant->id,
                    'user_id' => $membership->user_id,
                    'role_id' => $resolvedRole->id,
                    'assigned_by' => $actor->id,
                ]);
            }
        });

        $this->auditLogger->record(
            action: 'tenancy.member.updated',
            resourceType: 'tenant_membership',
            resourceId: $membership->id,
            metadata: ['role' => $resolvedRole->slug, 'status' => $membership->status],
            tenantId: $tenant->id,
            actorUserId: $actor->id,
        );

        $memberUser = $membership->user;

        return [
            'id' => $membership->id,
            'user_id' => $membership->user_id,
            'name' => $memberUser !== null ? $memberUser->name : '',
            'email' => $memberUser !== null ? $memberUser->email : '',
            'job_title' => $memberUser?->job_title,
            'role' => $resolvedRole->slug,
            'role_name' => $resolvedRole->name,
            'status' => $membership->status,
            'joined_at' => $membership->joined_at?->toISOString(),
            'created_at' => $membership->created_at->toISOString(),
        ];
    }

    public function removeMember(
        Tenant $tenant,
        User $actor,
        string $actorRoleSlug,
        string $membershipId,
    ): void {
        /** @var TenantMembership $membership */
        $membership = TenantMembership::query()
            ->where('tenant_id', $tenant->id)
            ->where('id', $membershipId)
            ->firstOrFail();

        /** @var UserRole|null $currentUserRole */
        $currentUserRole = UserRole::query()
            ->with('role')
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $membership->user_id)
            ->first();

        $currentRoleModel = $currentUserRole?->role;
        $currentRoleSlug = $currentRoleModel !== null ? $currentRoleModel->slug : 'viewer';

        if ($currentRoleSlug === 'owner' && $actorRoleSlug !== 'owner') {
            abort(403, 'Only an Owner can remove another Owner.');
        }

        if ($currentRoleSlug === 'owner' && $this->countActiveOwners($tenant->id) <= 1) {
            throw ValidationException::withMessages([
                'membership' => ['Cannot remove the last active Owner of an organization.'],
            ]);
        }

        DB::transaction(function () use ($tenant, $membership): void {
            UserRole::query()
                ->where('tenant_id', $tenant->id)
                ->where('user_id', $membership->user_id)
                ->delete();

            $membership->delete();
        });

        $this->auditLogger->record(
            action: 'tenancy.member.removed',
            resourceType: 'tenant_membership',
            resourceId: $membershipId,
            metadata: ['removed_user_id' => $membership->user_id],
            tenantId: $tenant->id,
            actorUserId: $actor->id,
        );
    }

    private function countActiveOwners(string $tenantId): int
    {
        return (int) DB::table('user_roles')
            ->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->join('tenant_memberships', function ($join): void {
                $join->on('tenant_memberships.tenant_id', '=', 'user_roles.tenant_id')
                    ->on('tenant_memberships.user_id', '=', 'user_roles.user_id');
            })
            ->where('user_roles.tenant_id', $tenantId)
            ->where('roles.slug', 'owner')
            ->where('tenant_memberships.status', 'active')
            ->count();
    }
}
