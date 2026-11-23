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
 * @property string $workflow_definition_id
 * @property string|null $agent_run_id
 * @property string|null $triggered_by
 * @property string $status
 * @property array<string, mixed>|null $input_json
 * @property array<string, mixed>|null $output_json
 * @property string|null $error_message
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class WorkflowRun extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'workflow_runs';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'workflow_definition_id',
        'agent_run_id',
        'triggered_by',
        'status',
        'input_json',
        'output_json',
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
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(bool $includeSteps = false, ?string $workflowName = null): array
    {
        $resolvedName = $workflowName;
        if ($resolvedName === null) {
            /** @var WorkflowDefinition|null $def */
            $def = WorkflowDefinition::query()
                ->where('tenant_id', $this->tenant_id)
                ->where('id', $this->workflow_definition_id)
                ->first();
            $resolvedName = $def?->name;
        }

        $approval = ApprovalRequest::query()
            ->where('tenant_id', $this->tenant_id)
            ->where('workflow_run_id', $this->id)
            ->orderByDesc('created_at')
            ->first();

        $data = [
            'id' => $this->id,
            'workflow_definition_id' => $this->workflow_definition_id,
            'workflow_name' => $resolvedName,
            'agent_run_id' => $this->agent_run_id,
            'triggered_by' => $this->triggered_by,
            'status' => $this->status,
            'input' => $this->input_json ?? [],
            'output' => $this->output_json,
            'error_message' => $this->error_message,
            'approval_request_id' => $approval?->id,
            'approval_status' => $approval?->status,
            'started_at' => $this->started_at?->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(),
            'created_at' => $this->created_at->toISOString(),
        ];

        if ($includeSteps) {
            $data['steps'] = WorkflowStep::query()
                ->where('tenant_id', $this->tenant_id)
                ->where('workflow_run_id', $this->id)
                ->orderBy('step_order')
                ->get()
                ->map(static fn (WorkflowStep $s): array => $s->toApiArray())
                ->values()
                ->all();
        }

        return $data;
    }
}
