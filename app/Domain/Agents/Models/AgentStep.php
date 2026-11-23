<?php

declare(strict_types=1);

namespace App\Domain\Agents\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $agent_run_id
 * @property int $step_number
 * @property string $step_type
 * @property string|null $tool_name
 * @property array<string, mixed>|null $input_json
 * @property array<string, mixed>|null $output_json
 * @property string $status
 * @property int $tokens_used
 * @property int $latency_ms
 * @property string|null $error_message
 * @property Carbon $created_at
 */
class AgentStep extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'agent_steps';

    public $incrementing = false;

    protected $keyType = 'string';

    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'agent_run_id',
        'step_number',
        'step_type',
        'tool_name',
        'input_json',
        'output_json',
        'status',
        'tokens_used',
        'latency_ms',
        'error_message',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'step_number' => 'integer',
            'input_json' => 'array',
            'output_json' => 'array',
            'tokens_used' => 'integer',
            'latency_ms' => 'integer',
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
            'step_number' => (int) $this->step_number,
            'step_type' => $this->step_type,
            'tool_name' => $this->tool_name,
            'input' => $this->input_json ?? [],
            'output' => $this->output_json ?? [],
            'status' => $this->status,
            'tokens_used' => (int) $this->tokens_used,
            'latency_ms' => (int) $this->latency_ms,
            'error_message' => $this->error_message,
            'created_at' => $this->created_at->toISOString(),
        ];
    }
}
