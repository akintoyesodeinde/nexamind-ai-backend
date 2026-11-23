<?php

declare(strict_types=1);

namespace App\Domain\Knowledge\Services;

use App\Domain\Audit\Contracts\AuditLoggerInterface;
use App\Domain\Identity\Models\User;
use App\Domain\Knowledge\Models\KnowledgeBase;
use App\Domain\Knowledge\Models\KnowledgeBasePermission;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class KnowledgeBaseService
{
    public function __construct(
        private readonly AuthorizedScopeResolver $scopeResolver,
        private readonly AuditLoggerInterface $auditLogger,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function listPermitted(Tenant $tenant, User $user): array
    {
        $kbIds = $this->scopeResolver->authorizedKnowledgeBaseIds($tenant->id, $user->id);
        if ($kbIds === []) {
            return [];
        }

        $kbs = KnowledgeBase::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('id', $kbIds)
            ->orderBy('name')
            ->get();

        $docCounts = DB::table('documents')
            ->select('knowledge_base_id', DB::raw('COUNT(*) as cnt'))
            ->where('tenant_id', $tenant->id)
            ->whereIn('knowledge_base_id', $kbIds)
            ->where('status', '!=', 'deleted')
            ->groupBy('knowledge_base_id')
            ->pluck('cnt', 'knowledge_base_id');

        $rows = [];
        foreach ($kbs as $kb) {
            $count = (int) ($docCounts[$kb->id] ?? $docCounts[strtoupper($kb->id)] ?? $docCounts[strtolower($kb->id)] ?? 0);
            $rows[] = $kb->toApiArray($count);
        }

        return $rows;
    }

    /**
     * @param  array{name: string, slug?: string|null, description?: string|null, visibility?: string}  $data
     */
    public function create(Tenant $tenant, User $actor, array $data): KnowledgeBase
    {
        $slug = isset($data['slug']) && trim($data['slug']) !== ''
            ? Str::slug($data['slug'])
            : Str::slug($data['name']);

        if ($slug === '') {
            $slug = 'kb-'.Str::lower(Str::random(6));
        }

        if (KnowledgeBase::query()
            ->where('tenant_id', $tenant->id)
            ->where('slug', $slug)
            ->exists()) {
            throw ValidationException::withMessages([
                'slug' => ['A knowledge base with this slug already exists in this organization.'],
            ]);
        }

        /** @var KnowledgeBase $kb */
        $kb = DB::transaction(function () use ($tenant, $actor, $data, $slug): KnowledgeBase {
            $created = KnowledgeBase::query()->create([
                'tenant_id' => $tenant->id,
                'name' => trim($data['name']),
                'slug' => $slug,
                'description' => isset($data['description']) ? trim($data['description']) : null,
                'visibility' => $data['visibility'] ?? 'restricted',
                'status' => 'active',
                'created_by' => $actor->id,
            ]);

            KnowledgeBasePermission::query()->create([
                'tenant_id' => $tenant->id,
                'knowledge_base_id' => $created->id,
                'principal_type' => 'user',
                'principal_id' => $actor->id,
                'permission_level' => 'admin',
                'granted_by' => $actor->id,
            ]);

            return $created;
        });

        $this->auditLogger->record(
            action: 'knowledge.base.created',
            resourceType: 'knowledge_base',
            resourceId: $kb->id,
            metadata: ['slug' => $kb->slug, 'visibility' => $kb->visibility],
            tenantId: $tenant->id,
            actorUserId: $actor->id,
        );

        return $kb;
    }

    /**
     * @param  array{name?: string, description?: string|null, visibility?: string, status?: string}  $data
     */
    public function update(Tenant $tenant, User $actor, KnowledgeBase $kb, array $data): KnowledgeBase
    {
        $kb->fill(array_filter([
            'name' => isset($data['name']) ? trim($data['name']) : null,
            'description' => array_key_exists('description', $data) ? ($data['description'] !== null ? trim($data['description']) : null) : null,
            'visibility' => $data['visibility'] ?? null,
            'status' => $data['status'] ?? null,
        ], static fn (mixed $v): bool => $v !== null));
        $kb->save();

        $this->auditLogger->record(
            action: 'knowledge.base.updated',
            resourceType: 'knowledge_base',
            resourceId: $kb->id,
            metadata: ['visibility' => $kb->visibility, 'status' => $kb->status],
            tenantId: $tenant->id,
            actorUserId: $actor->id,
        );

        return $kb->refresh();
    }

    /**
     * @param  list<array{principal_type: string, principal_id: string, permission_level: string}>  $grants
     * @return list<array<string, mixed>>
     */
    public function syncPermissions(Tenant $tenant, User $actor, KnowledgeBase $kb, array $grants): array
    {
        DB::transaction(function () use ($tenant, $actor, $kb, $grants): void {
            KnowledgeBasePermission::query()
                ->where('tenant_id', $tenant->id)
                ->where('knowledge_base_id', $kb->id)
                ->delete();

            foreach ($grants as $grant) {
                KnowledgeBasePermission::query()->create([
                    'tenant_id' => $tenant->id,
                    'knowledge_base_id' => $kb->id,
                    'principal_type' => $grant['principal_type'],
                    'principal_id' => $grant['principal_id'],
                    'permission_level' => $grant['permission_level'],
                    'granted_by' => $actor->id,
                ]);
            }
        });

        $this->auditLogger->record(
            action: 'knowledge.permissions.updated',
            resourceType: 'knowledge_base',
            resourceId: $kb->id,
            metadata: ['grants_count' => count($grants)],
            tenantId: $tenant->id,
            actorUserId: $actor->id,
        );

        return $this->listPermissions($tenant, $kb);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listPermissions(Tenant $tenant, KnowledgeBase $kb): array
    {
        return KnowledgeBasePermission::query()
            ->where('tenant_id', $tenant->id)
            ->where('knowledge_base_id', $kb->id)
            ->orderBy('principal_type')
            ->get()
            ->map(static fn (KnowledgeBasePermission $p): array => [
                'id' => $p->id,
                'principal_type' => $p->principal_type,
                'principal_id' => $p->principal_id,
                'permission_level' => $p->permission_level,
                'created_at' => $p->created_at->toISOString(),
            ])
            ->values()
            ->all();
    }
}
