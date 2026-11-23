<?php

declare(strict_types=1);

namespace App\Domain\Knowledge\Services;

use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentPermission;
use App\Domain\Knowledge\Models\KnowledgeBase;
use App\Domain\Knowledge\Models\KnowledgeBasePermission;
use App\Domain\Tenancy\Models\UserRole;
use Illuminate\Support\Facades\DB;

/**
 * Single authoritative pre-retrieval ACL resolver for Knowledge Bases and Documents.
 *
 * Security invariants:
 * - Every query strictly enforces tenant_id.
 * - Organization Owners do NOT automatically bypass confidential (`access_policy = 'restricted'`)
 *   documents or explicit `deny` grants.
 * - Document ACL filtering ALWAYS happens BEFORE vector retrieval.
 */
final class AuthorizedScopeResolver
{
    public function resolveUserRoleId(string $tenantId, string $userId): ?string
    {
        /** @var UserRole|null $ur */
        $ur = UserRole::query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->first();

        return $ur?->role_id;
    }

    /**
     * @return list<string>
     */
    public function authorizedKnowledgeBaseIds(
        string $tenantId,
        string $userId,
        ?string $roleId = null,
        string $minimumLevel = 'read',
    ): array {
        $resolvedRoleId = $roleId ?? $this->resolveUserRoleId($tenantId, $userId);
        $allowedLevels = match ($minimumLevel) {
            'admin' => ['admin'],
            'write' => ['write', 'admin'],
            default => ['read', 'write', 'admin'],
        };

        $kbs = KnowledgeBase::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->get();

        if ($kbs->isEmpty()) {
            return [];
        }

        $kbIds = $kbs->pluck('id')->all();

        $permRows = KnowledgeBasePermission::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('knowledge_base_id', $kbIds)
            ->whereIn('permission_level', $allowedLevels)
            ->where(function ($q) use ($userId, $resolvedRoleId): void {
                $q->where(function ($u) use ($userId): void {
                    $u->where('principal_type', 'user')->where('principal_id', $userId);
                });
                if ($resolvedRoleId !== null) {
                    $q->orWhere(function ($r) use ($resolvedRoleId): void {
                        $r->where('principal_type', 'role')->where('principal_id', $resolvedRoleId);
                    });
                }
            })
            ->pluck('knowledge_base_id')
            ->map(static fn (mixed $id): string => strtolower((string) $id))
            ->unique()
            ->all();

        $grantedSet = array_flip($permRows);
        $authorized = [];

        foreach ($kbs as $kb) {
            $kbLower = strtolower($kb->id);
            $isCreator = strtolower($kb->created_by) === strtolower($userId);

            if ($isCreator || isset($grantedSet[$kbLower])) {
                $authorized[] = $kb->id;

                continue;
            }

            if ($minimumLevel === 'read' && $kb->visibility === 'tenant') {
                $authorized[] = $kb->id;
            }
        }

        return $authorized;
    }

    public function canReadKnowledgeBase(string $tenantId, string $userId, string $knowledgeBaseId): bool
    {
        $ids = array_map('strtolower', $this->authorizedKnowledgeBaseIds($tenantId, $userId, null, 'read'));

        return in_array(strtolower($knowledgeBaseId), $ids, true);
    }

    public function canWriteKnowledgeBase(string $tenantId, string $userId, string $knowledgeBaseId, ?string $roleSlug = null): bool
    {
        $ids = array_map('strtolower', $this->authorizedKnowledgeBaseIds($tenantId, $userId, null, 'write'));
        if (in_array(strtolower($knowledgeBaseId), $ids, true)) {
            return true;
        }

        if (in_array($roleSlug, ['owner', 'admin', 'manager'], true)) {
            return KnowledgeBase::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('id', $knowledgeBaseId)
                ->where('status', 'active')
                ->where('visibility', 'tenant')
                ->exists();
        }

        return false;
    }

    public function canManageKnowledgeBase(string $tenantId, string $userId, string $knowledgeBaseId, ?string $roleSlug = null): bool
    {
        $ids = array_map('strtolower', $this->authorizedKnowledgeBaseIds($tenantId, $userId, null, 'admin'));
        if (in_array(strtolower($knowledgeBaseId), $ids, true)) {
            return true;
        }

        if (in_array($roleSlug, ['owner', 'admin'], true)) {
            return KnowledgeBase::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('id', $knowledgeBaseId)
                ->exists();
        }

        return false;
    }

    /**
     * Resolves exact authorized Document IDs strictly BEFORE vector retrieval or listing.
     *
     * @param  list<string>|null  $requestedKnowledgeBaseIds
     * @return array{knowledge_base_ids: list<string>, document_ids: list<string>, unauthorized_kb_ids: list<string>}
     */
    public function resolveAuthorizedSearchScope(
        string $tenantId,
        string $userId,
        ?array $requestedKnowledgeBaseIds = null,
        bool $readyOnly = true,
    ): array {
        $roleId = $this->resolveUserRoleId($tenantId, $userId);
        $allowedKbIds = $this->authorizedKnowledgeBaseIds($tenantId, $userId, $roleId, 'read');
        $allowedKbLower = array_flip(array_map('strtolower', $allowedKbIds));

        $unauthorizedKbIds = [];
        if ($requestedKnowledgeBaseIds !== null && $requestedKnowledgeBaseIds !== []) {
            $filtered = [];
            foreach ($requestedKnowledgeBaseIds as $reqId) {
                $lower = strtolower($reqId);
                if (isset($allowedKbLower[$lower])) {
                    $filtered[] = $reqId;
                } else {
                    $unauthorizedKbIds[] = $reqId;
                }
            }
            $effectiveKbIds = array_values(array_unique($filtered));
        } else {
            $effectiveKbIds = $allowedKbIds;
        }

        if ($effectiveKbIds === []) {
            return [
                'knowledge_base_ids' => [],
                'document_ids' => [],
                'unauthorized_kb_ids' => $unauthorizedKbIds,
            ];
        }

        $docQuery = Document::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('knowledge_base_id', $effectiveKbIds)
            ->where('status', '!=', 'deleted');

        if ($readyOnly) {
            $docQuery->where('status', 'ready');
        }

        $candidates = $docQuery->get(['id', 'access_policy']);
        if ($candidates->isEmpty()) {
            return [
                'knowledge_base_ids' => $effectiveKbIds,
                'document_ids' => [],
                'unauthorized_kb_ids' => $unauthorizedKbIds,
            ];
        }

        $candidateIds = $candidates->pluck('id')->all();

        $docPerms = DocumentPermission::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('document_id', $candidateIds)
            ->where(function ($q) use ($userId, $roleId): void {
                $q->where(function ($u) use ($userId): void {
                    $u->where('principal_type', 'user')->where('principal_id', $userId);
                });
                if ($roleId !== null) {
                    $q->orWhere(function ($r) use ($roleId): void {
                        $r->where('principal_type', 'role')->where('principal_id', $roleId);
                    });
                }
            })
            ->get(['document_id', 'permission_level']);

        $deniedDocs = [];
        $explicitlyAllowedDocs = [];
        foreach ($docPerms as $perm) {
            $docLower = strtolower($perm->document_id);
            if ($perm->permission_level === 'deny') {
                $deniedDocs[$docLower] = true;
            } else {
                $explicitlyAllowedDocs[$docLower] = true;
            }
        }

        $authorizedDocIds = [];
        foreach ($candidates as $doc) {
            $docLower = strtolower($doc->id);
            if (isset($deniedDocs[$docLower])) {
                continue;
            }

            if ($doc->access_policy === 'inherit') {
                $authorizedDocIds[] = $doc->id;
            } elseif ($doc->access_policy === 'restricted' && isset($explicitlyAllowedDocs[$docLower])) {
                $authorizedDocIds[] = $doc->id;
            }
        }

        return [
            'knowledge_base_ids' => $effectiveKbIds,
            'document_ids' => $authorizedDocIds,
            'unauthorized_kb_ids' => $unauthorizedKbIds,
        ];
    }

    public function canReadDocument(string $tenantId, string $userId, Document $document): bool
    {
        if (strtolower($document->tenant_id) !== strtolower($tenantId) || $document->status === 'deleted') {
            return false;
        }

        if (! $this->canReadKnowledgeBase($tenantId, $userId, $document->knowledge_base_id)) {
            return false;
        }

        $roleId = $this->resolveUserRoleId($tenantId, $userId);

        $perms = DB::table('document_permissions')
            ->where('tenant_id', $tenantId)
            ->where('document_id', $document->id)
            ->where(function ($q) use ($userId, $roleId): void {
                $q->where(function ($u) use ($userId): void {
                    $u->where('principal_type', 'user')->where('principal_id', $userId);
                });
                if ($roleId !== null) {
                    $q->orWhere(function ($r) use ($roleId): void {
                        $r->where('principal_type', 'role')->where('principal_id', $roleId);
                    });
                }
            })
            ->pluck('permission_level')
            ->all();

        if (in_array('deny', $perms, true)) {
            return false;
        }

        if ($document->access_policy === 'restricted') {
            return $perms !== [];
        }

        return true;
    }

    public function canManageDocument(string $tenantId, string $userId, Document $document, ?string $roleSlug = null): bool
    {
        if (strtolower($document->tenant_id) !== strtolower($tenantId)) {
            return false;
        }

        if (strtolower($document->uploaded_by) === strtolower($userId)) {
            return true;
        }

        $roleId = $this->resolveUserRoleId($tenantId, $userId);
        $hasDocAdmin = DB::table('document_permissions')
            ->where('tenant_id', $tenantId)
            ->where('document_id', $document->id)
            ->where('permission_level', 'admin')
            ->where(function ($q) use ($userId, $roleId): void {
                $q->where(function ($u) use ($userId): void {
                    $u->where('principal_type', 'user')->where('principal_id', $userId);
                });
                if ($roleId !== null) {
                    $q->orWhere(function ($r) use ($roleId): void {
                        $r->where('principal_type', 'role')->where('principal_id', $roleId);
                    });
                }
            })
            ->exists();

        if ($hasDocAdmin) {
            return true;
        }

        if ($document->access_policy === 'restricted') {
            // Even Owners cannot silently manage confidential restricted documents unless explicitly granted
            return false;
        }

        return $this->canManageKnowledgeBase($tenantId, $userId, $document->knowledge_base_id, $roleSlug);
    }
}
