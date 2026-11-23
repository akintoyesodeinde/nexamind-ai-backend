<?php

declare(strict_types=1);

namespace App\Domain\Conversations\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $conversation_id
 * @property string $role
 * @property string $content
 * @property string|null $model_alias
 * @property string $status
 * @property bool $abstained
 * @property int $input_tokens
 * @property int $output_tokens
 * @property string $cost_usd
 * @property int $latency_ms
 * @property string|null $feedback
 * @property string|null $feedback_note
 * @property array<string, mixed>|null $metadata_json
 * @property Carbon $created_at
 */
class Message extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'messages';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'conversation_id',
        'role',
        'content',
        'model_alias',
        'status',
        'abstained',
        'input_tokens',
        'output_tokens',
        'cost_usd',
        'latency_ms',
        'feedback',
        'feedback_note',
        'metadata_json',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'abstained' => 'boolean',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cost_usd' => 'decimal:6',
            'latency_ms' => 'integer',
            'metadata_json' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'conversation_id');
    }

    /**
     * @return HasMany<MessageCitation, $this>
     */
    public function citations(): HasMany
    {
        return $this->hasMany(MessageCitation::class, 'message_id')->orderBy('citation_index');
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        $citations = $this->relationLoaded('citations')
            ? $this->citations->map(static fn (MessageCitation $c): array => $c->toApiArray())->values()->all()
            : [];

        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'role' => $this->role,
            'content' => $this->content,
            'model_alias' => $this->model_alias,
            'status' => $this->status,
            'abstained' => $this->abstained,
            'citations' => $citations,
            'usage' => [
                'input_tokens' => $this->input_tokens,
                'output_tokens' => $this->output_tokens,
            ],
            'cost_usd' => number_format((float) $this->cost_usd, 6, '.', ''),
            'latency_ms' => $this->latency_ms,
            'feedback' => $this->feedback,
            'feedback_note' => $this->feedback_note,
            'created_at' => $this->created_at->toISOString(),
        ];
    }
}
