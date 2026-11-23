<?php

declare(strict_types=1);

namespace App\Domain\AI\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $ai_provider_id
 * @property string $alias
 * @property string $provider_model_name
 * @property string $capability
 * @property int $max_context_tokens
 * @property string $input_cost_per_1k_usd
 * @property string $output_cost_per_1k_usd
 * @property bool $is_active
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class AiModel extends Model
{
    use HasUuids;

    protected $table = 'ai_models';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'ai_provider_id',
        'alias',
        'provider_model_name',
        'capability',
        'max_context_tokens',
        'input_cost_per_1k_usd',
        'output_cost_per_1k_usd',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'max_context_tokens' => 'integer',
            'input_cost_per_1k_usd' => 'decimal:6',
            'output_cost_per_1k_usd' => 'decimal:6',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<AiProvider, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'ai_provider_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        $provider = $this->provider;

        return [
            'id' => $this->id,
            'alias' => $this->alias,
            'provider_model_name' => $this->provider_model_name,
            'provider_key' => $provider !== null ? $provider->provider_key : 'mock',
            'capability' => $this->capability,
            'max_context_tokens' => $this->max_context_tokens,
            'input_cost_per_1k_usd' => number_format((float) $this->input_cost_per_1k_usd, 6, '.', ''),
            'output_cost_per_1k_usd' => number_format((float) $this->output_cost_per_1k_usd, 6, '.', ''),
            'is_active' => $this->is_active,
        ];
    }
}
