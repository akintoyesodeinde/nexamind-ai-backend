<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Identity\Models\Permission;
use App\Domain\Identity\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RbacSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            ['slug' => 'tenant.manage', 'module' => 'tenancy', 'description' => 'Full organization lifecycle management'],
            ['slug' => 'tenant.settings.manage', 'module' => 'tenancy', 'description' => 'Configure tenant AI budgets, upload limits, and policies'],
            ['slug' => 'users.manage', 'module' => 'identity', 'description' => 'Invite, update, and remove organization members and roles'],
            ['slug' => 'knowledge.create', 'module' => 'knowledge', 'description' => 'Create and manage knowledge bases'],
            ['slug' => 'documents.upload', 'module' => 'documents', 'description' => 'Upload and manage documents in permitted knowledge bases'],
            ['slug' => 'chat.ask', 'module' => 'conversations', 'description' => 'Ask grounded AI questions against permitted documents'],
            ['slug' => 'agents.manage', 'module' => 'agents', 'description' => 'Create and configure AI agents and allowlisted tools'],
            ['slug' => 'workflows.manage', 'module' => 'workflows', 'description' => 'Create and launch automated business workflows'],
            ['slug' => 'approvals.act', 'module' => 'workflows', 'description' => 'Approve or reject assigned workflow approval requests'],
            ['slug' => 'analytics.view', 'module' => 'analytics', 'description' => 'View AI usage, cost, and evaluation dashboards'],
            ['slug' => 'audit.view', 'module' => 'audit', 'description' => 'Search tenant-scoped security and audit logs'],
        ];

        $permissionMap = [];
        foreach ($permissions as $def) {
            /** @var Permission $perm */
            $perm = Permission::query()->updateOrCreate(
                ['slug' => $def['slug']],
                ['module' => $def['module'], 'description' => $def['description']],
            );
            $permissionMap[$perm->slug] = $perm->id;
        }

        $roles = [
            'owner' => [
                'name' => 'Owner',
                'description' => 'Full organization governance, RBAC administration, AI configuration, and audit visibility',
                'permissions' => array_keys($permissionMap),
            ],
            'admin' => [
                'name' => 'Admin',
                'description' => 'Manage users, roles, tenant settings, knowledge bases, agents, workflows, and audit logs',
                'permissions' => [
                    'tenant.settings.manage',
                    'users.manage',
                    'knowledge.create',
                    'documents.upload',
                    'chat.ask',
                    'agents.manage',
                    'workflows.manage',
                    'approvals.act',
                    'analytics.view',
                    'audit.view',
                ],
            ],
            'manager' => [
                'name' => 'Manager',
                'description' => 'Manage knowledge bases, upload documents, configure agents/workflows, approve actions, limited audit view',
                'permissions' => [
                    'knowledge.create',
                    'documents.upload',
                    'chat.ask',
                    'agents.manage',
                    'workflows.manage',
                    'approvals.act',
                    'analytics.view',
                    'audit.view',
                ],
            ],
            'member' => [
                'name' => 'Member',
                'description' => 'Upload permitted documents, ask AI questions, and act on assigned approvals',
                'permissions' => [
                    'documents.upload',
                    'chat.ask',
                    'approvals.act',
                ],
            ],
            'viewer' => [
                'name' => 'Viewer',
                'description' => 'Read-only access to ask AI questions within explicitly permitted knowledge bases',
                'permissions' => [
                    'chat.ask',
                ],
            ],
        ];

        foreach ($roles as $slug => $meta) {
            /** @var Role $role */
            $role = Role::query()->updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $meta['name'],
                    'description' => $meta['description'],
                    'is_system' => true,
                ],
            );

            DB::table('role_permissions')->where('role_id', $role->id)->delete();
            $pivotRows = [];
            foreach ($meta['permissions'] as $permSlug) {
                $pivotRows[] = [
                    'role_id' => $role->id,
                    'permission_id' => $permissionMap[$permSlug],
                ];
            }
            if ($pivotRows !== []) {
                DB::table('role_permissions')->insert($pivotRows);
            }
        }
    }
}
