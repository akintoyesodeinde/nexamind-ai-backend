<?php

declare(strict_types=1);

namespace App\Domain\Conversations\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $message_id
 * @property string $document_id
 * @property string $chunk_id
 * @property int $citation_index
 * @property string $document_name
 * @property int|null $page_number
 * @property string|null $section_heading
 * @property string $excerpt
 * @property string $similarity_score
 * @property bool $verified
 * @property Carbon $created_at
 */
class MessageCitation extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'message_citations';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'message_id',
        'document_id',
        'chunk_id',
        'citation_index',
        'document_name',
        'page_number',
        'section_heading',
        'excerpt',
        'similarity_score',
        'verified',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'citation_index' => 'integer',
            'page_number' => 'integer',
            'similarity_score' => 'decimal:6',
            'verified' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'index' => $this->citation_index,
            'document_id' => $this->document_id,
            'document_name' => $this->document_name,
            'page' => $this->page_number,
            'section_heading' => $this->section_heading,
            'chunk_id' => $this->chunk_id,
            'excerpt' => $this->excerpt,
            'similarity_score' => round((float) $this->similarity_score, 4),
            'verified' => $this->verified,
        ];
    }
}
