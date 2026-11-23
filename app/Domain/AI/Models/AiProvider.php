<?php

declare(strict_types=1);

namespace App\Domain\AI\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string|null $tenant_id
 * @property string $provider_key
 * @property string $display_name
 * @property string|null $endpoint_url
 * @property array<string, mixed>|null $encrypted_config
 * @property bool $is_active
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class AiProvider extends Model
{
    use HasUuids;

    protected $table = 'ai_providers';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'provider_key',
        'display_name',
        'endpoint_url',
        'encrypted_config',
        'is_active',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'encrypted_config',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'encrypted_config' => 'encrypted:array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<AiModel, $this>
     */
    public function models(): HasMany
    {
        return $this->hasMany(AiModel::class, 'ai_provider_id');
    }
}
