<?php

declare(strict_types=1);

namespace App\Domain\Conversations\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $user_id
 * @property string $title
 * @property string $default_model_alias
 * @property array<int, string>|null $knowledge_base_ids_json
 * @property string $status
 * @property Carbon|null $last_message_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Conversation extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'conversations';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'user_id',
        'title',
        'default_model_alias',
        'knowledge_base_ids_json',
        'status',
        'last_message_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'knowledge_base_ids_json' => 'array',
            'last_message_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'conversation_id')->orderBy('created_at');
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(?int $messagesCount = null): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'default_model_alias' => $this->default_model_alias,
            'knowledge_base_ids' => $this->knowledge_base_ids_json ?? [],
            'status' => $this->status,
            'messages_count' => $messagesCount ?? $this->messages()->count(),
            'last_message_at' => $this->last_message_at?->toISOString(),
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }
}
