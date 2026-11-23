<?php

declare(strict_types=1);

namespace App\Domain\Documents\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $knowledge_base_id
 * @property string $document_id
 * @property string $document_version_id
 * @property int $chunk_index
 * @property int|null $page_number
 * @property string|null $section_heading
 * @property int $char_start
 * @property int $char_end
 * @property int $token_estimate
 * @property string $content
 * @property string $content_hash
 * @property string|null $embedding_model
 * @property string|null $embedding_json
 * @property Carbon $created_at
 */
class DocumentChunk extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'document_chunks';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'knowledge_base_id',
        'document_id',
        'document_version_id',
        'chunk_index',
        'page_number',
        'section_heading',
        'char_start',
        'char_end',
        'token_estimate',
        'content',
        'content_hash',
        'embedding_model',
        'embedding_json',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'chunk_index' => 'integer',
            'page_number' => 'integer',
            'char_start' => 'integer',
            'char_end' => 'integer',
            'token_estimate' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'document_id' => $this->document_id,
            'document_version_id' => $this->document_version_id,
            'chunk_index' => $this->chunk_index,
            'page_number' => $this->page_number,
            'section_heading' => $this->section_heading,
            'char_start' => $this->char_start,
            'char_end' => $this->char_end,
            'token_estimate' => $this->token_estimate,
            'content' => $this->content,
            'embedding_model' => $this->embedding_model,
            'created_at' => $this->created_at->toISOString(),
        ];
    }
}
