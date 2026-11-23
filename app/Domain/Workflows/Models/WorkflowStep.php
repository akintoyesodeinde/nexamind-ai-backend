<?php

declare(strict_types=1);

namespace App\Domain\Workflows\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $workflow_run_id
 * @property int $step_order
 * @property string $step_key
 * @property string $name
 * @property string $status
 * @property array<string, mixed>|null $payload_json
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon $created_at
 */
class WorkflowStep extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'workflow_steps';

    public $incrementing = false;

    protected $keyType = 'string';

    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'workflow_run_id',
        'step_order',
        'step_key',
        'name',
        'status',
        'payload_json',
        'started_at',
        'completed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'step_order' => 'integer',
            'payload_json' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
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
            'step_order' => (int) $this->step_order,
            'step_key' => $this->step_key,
            'name' => $this->name,
            'status' => $this->status,
            'payload' => $this->payload_json ?? [],
            'started_at' => $this->started_at?->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(),
            'created_at' => $this->created_at->toISOString(),
        ];
    }
}
