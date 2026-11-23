<?php

declare(strict_types=1);

namespace App\Domain\Agents\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string|null $created_by
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string $system_prompt
 * @property string $model_alias
 * @property array<int, string>|null $allowed_kb_ids_json
 * @property int $max_steps
 * @property int $max_tokens_per_run
 * @property bool $require_approval
 * @property string $status
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Agent extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'agents';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'created_by',
        'name',
        'slug',
        'description',
        'system_prompt',
        'model_alias',
        'allowed_kb_ids_json',
        'max_steps',
        'max_tokens_per_run',
        'require_approval',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'allowed_kb_ids_json' => 'array',
            'max_steps' => 'integer',
            'max_tokens_per_run' => 'integer',
            'require_approval' => 'boolean',
        ];
    }

    /**
     * @return HasMany<AgentTool, $this>
     */
    public function tools(): HasMany
    {
        return $this->hasMany(AgentTool::class, 'agent_id')->orderBy('tool_name');
    }

    /**
     * @return HasMany<AgentRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(AgentRun::class, 'agent_id')->orderByDesc('created_at');
    }

    /**
     * @param  list<array<string, mixed>>|null  $toolsOverride
     * @return array<string, mixed>
     */
    public function toApiArray(?array $toolsOverride = null): array
    {
        $tools = $toolsOverride ?? AgentTool::query()
            ->where('tenant_id', $this->tenant_id)
            ->where('agent_id', $this->id)
            ->orderBy('tool_name')
            ->get()
            ->map(static fn (AgentTool $t): array => $t->toApiArray())
            ->values()
            ->all();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'system_prompt' => $this->system_prompt,
            'model_alias' => $this->model_alias,
            'allowed_kb_ids' => $this->allowed_kb_ids_json ?? [],
            'max_steps' => (int) $this->max_steps,
            'max_tokens_per_run' => (int) $this->max_tokens_per_run,
            'require_approval' => (bool) $this->require_approval,
            'status' => $this->status,
            'tools' => $tools,
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }
}
