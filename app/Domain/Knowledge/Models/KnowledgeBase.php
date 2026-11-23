<?php

declare(strict_types=1);

namespace App\Domain\Knowledge\Models;

use App\Domain\Documents\Models\Document;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string $visibility
 * @property string $status
 * @property string $created_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class KnowledgeBase extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'knowledge_bases';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'name',
        'slug',
        'description',
        'visibility',
        'status',
        'created_by',
    ];

    /**
     * @return HasMany<KnowledgeBasePermission, $this>
     */
    public function permissions(): HasMany
    {
        return $this->hasMany(KnowledgeBasePermission::class, 'knowledge_base_id');
    }

    /**
     * @return HasMany<Document, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'knowledge_base_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(?int $documentsCount = null): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'visibility' => $this->visibility,
            'status' => $this->status,
            'created_by' => $this->created_by,
            'documents_count' => $documentsCount ?? $this->documents()->where('status', '!=', 'deleted')->count(),
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }
}
