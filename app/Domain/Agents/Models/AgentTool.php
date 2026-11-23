<?php

declare(strict_types=1);

namespace App\Domain\Agents\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $agent_id
 * @property string $tool_name
 * @property bool $is_enabled
 * @property bool $requires_approval
 * @property array<string, mixed>|null $config_json
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class AgentTool extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'agent_tools';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'agent_id',
        'tool_name',
        'is_enabled',
        'requires_approval',
        'config_json',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'requires_approval' => 'boolean',
            'config_json' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Agent, $this>
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'agent_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'tool_name' => $this->tool_name,
            'is_enabled' => (bool) $this->is_enabled,
            'requires_approval' => (bool) $this->requires_approval,
            'config' => $this->config_json ?? [],
        ];
    }
}
