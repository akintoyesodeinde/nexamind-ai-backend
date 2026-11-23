<?php

declare(strict_types=1);

namespace App\Domain\AI\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string|null $user_id
 * @property string|null $ai_model_id
 * @property string $provider_key
 * @property string $model_alias
 * @property string $operation_type
 * @property string|null $conversation_id
 * @property string|null $message_id
 * @property int $input_tokens
 * @property int $output_tokens
 * @property int $total_tokens
 * @property string $cost_usd
 * @property int $latency_ms
 * @property string $status
 * @property string|null $request_id
 * @property Carbon $created_at
 */
class AiUsageRecord extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'ai_usage_records';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'user_id',
        'ai_model_id',
        'provider_key',
        'model_alias',
        'operation_type',
        'conversation_id',
        'message_id',
        'input_tokens',
        'output_tokens',
        'total_tokens',
        'cost_usd',
        'latency_ms',
        'status',
        'request_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'total_tokens' => 'integer',
            'cost_usd' => 'decimal:6',
            'latency_ms' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
