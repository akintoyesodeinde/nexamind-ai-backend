<?php

declare(strict_types=1);

namespace App\Domain\Documents\Models;

use App\Domain\Knowledge\Models\KnowledgeBase;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $knowledge_base_id
 * @property string $uploaded_by
 * @property string $filename
 * @property string $mime_type
 * @property int $file_size_bytes
 * @property string $storage_path
 * @property string $checksum_sha256
 * @property int $current_version
 * @property string $access_policy
 * @property string $status
 * @property int|null $page_count
 * @property int $chunk_count
 * @property int $character_count
 * @property string|null $error_message
 * @property array<string, mixed>|null $metadata_json
 * @property Carbon|null $processed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Document extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'documents';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'knowledge_base_id',
        'uploaded_by',
        'filename',
        'mime_type',
        'file_size_bytes',
        'storage_path',
        'checksum_sha256',
        'current_version',
        'access_policy',
        'status',
        'page_count',
        'chunk_count',
        'character_count',
        'error_message',
        'metadata_json',
        'processed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'file_size_bytes' => 'integer',
            'current_version' => 'integer',
            'page_count' => 'integer',
            'chunk_count' => 'integer',
            'character_count' => 'integer',
            'metadata_json' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<KnowledgeBase, $this>
     */
    public function knowledgeBase(): BelongsTo
    {
        return $this->belongsTo(KnowledgeBase::class, 'knowledge_base_id');
    }

    /**
     * @return HasMany<DocumentVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class, 'document_id')->orderByDesc('version_number');
    }

    /**
     * @return HasMany<DocumentChunk, $this>
     */
    public function chunks(): HasMany
    {
        return $this->hasMany(DocumentChunk::class, 'document_id')->orderBy('chunk_index');
    }

    /**
     * @return HasMany<DocumentPermission, $this>
     */
    public function permissions(): HasMany
    {
        return $this->hasMany(DocumentPermission::class, 'document_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'knowledge_base_id' => $this->knowledge_base_id,
            'uploaded_by' => $this->uploaded_by,
            'filename' => $this->filename,
            'mime_type' => $this->mime_type,
            'file_size_bytes' => $this->file_size_bytes,
            'checksum_sha256' => $this->checksum_sha256,
            'current_version' => $this->current_version,
            'access_policy' => $this->access_policy,
            'status' => $this->status,
            'page_count' => $this->page_count,
            'chunk_count' => $this->chunk_count,
            'character_count' => $this->character_count,
            'error_message' => $this->error_message,
            'metadata' => $this->metadata_json ?? [],
            'processed_at' => $this->processed_at?->toISOString(),
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }
}
