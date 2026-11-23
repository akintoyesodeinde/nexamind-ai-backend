<?php

declare(strict_types=1);

namespace App\Domain\Agents\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $agent_id
 * @property string|null $triggered_by
 * @property string $goal
 * @property array<string, mixed>|null $input_json
 * @property array<string, mixed>|null $output_json
 * @property string $status
 * @property int $step_count
 * @property int $total_tokens
 * @property string $cost_usd
 * @property int $latency_ms
 * @property string|null $error_message
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class AgentRun extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'agent_runs';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'agent_id',
        'triggered_by',
        'goal',
        'input_json',
        'output_json',
        'status',
        'step_count',
        'total_tokens',
        'cost_usd',
        'latency_ms',
        'error_message',
        'started_at',
        'completed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'input_json' => 'array',
            'output_json' => 'array',
            'step_count' => 'integer',
            'total_tokens' => 'integer',
            'latency_ms' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
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
     * @return HasMany<AgentStep, $this>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(AgentStep::class, 'agent_run_id')->orderBy('step_number');
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(bool $includeSteps = false, ?string $agentName = null): array
    {
        $resolvedName = $agentName;
        if ($resolvedName === null) {
            /** @var Agent|null $agent */
            $agent = Agent::query()->where('tenant_id', $this->tenant_id)->where('id', $this->agent_id)->first();
            $resolvedName = $agent?->name;
        }

        $data = [
            'id' => $this->id,
            'agent_id' => $this->agent_id,
            'agent_name' => $resolvedName,
            'triggered_by' => $this->triggered_by,
            'goal' => $this->goal,
            'input' => $this->input_json ?? [],
            'output' => $this->output_json,
            'status' => $this->status,
            'step_count' => (int) $this->step_count,
            'total_tokens' => (int) $this->total_tokens,
            'cost_usd' => number_format((float) $this->cost_usd, 6, '.', ''),
            'latency_ms' => (int) $this->latency_ms,
            'error_message' => $this->error_message,
            'started_at' => $this->started_at?->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(),
            'created_at' => $this->created_at->toISOString(),
        ];

        if ($includeSteps) {
            $data['steps'] = AgentStep::query()
                ->where('tenant_id', $this->tenant_id)
                ->where('agent_run_id', $this->id)
                ->orderBy('step_number')
                ->get()
                ->map(static fn (AgentStep $s): array => $s->toApiArray())
                ->values()
                ->all();
        }

        return $data;
    }
}
