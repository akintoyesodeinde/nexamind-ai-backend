<?php

declare(strict_types=1);

namespace App\Domain\Workflows\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string|null $workflow_run_id
 * @property string|null $agent_run_id
 * @property string|null $requested_by
 * @property string|null $assigned_approver_id
 * @property string $required_role
 * @property string $title
 * @property string $action_type
 * @property string $summary
 * @property array<string, mixed> $payload_json
 * @property string $status
 * @property string|null $decided_by
 * @property string|null $decision_note
 * @property Carbon|null $decided_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class ApprovalRequest extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'approval_requests';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'workflow_run_id',
        'agent_run_id',
        'requested_by',
        'assigned_approver_id',
        'required_role',
        'title',
        'action_type',
        'summary',
        'payload_json',
        'status',
        'decided_by',
        'decision_note',
        'decided_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload_json' => 'array',
            'decided_at' => 'datetime',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        $requester = $this->requested_by !== null ? User::query()->find($this->requested_by) : null;
        $decider = $this->decided_by !== null ? User::query()->find($this->decided_by) : null;

        return [
            'id' => $this->id,
            'workflow_run_id' => $this->workflow_run_id,
            'agent_run_id' => $this->agent_run_id,
            'requested_by' => $this->requested_by,
            'requester_name' => $requester?->name,
            'requester_email' => $requester?->email,
            'assigned_approver_id' => $this->assigned_approver_id,
            'required_role' => $this->required_role,
            'title' => $this->title,
            'action_type' => $this->action_type,
            'summary' => $this->summary,
            'payload' => $this->payload_json ?? [],
            'status' => $this->status,
            'decided_by' => $this->decided_by,
            'decider_name' => $decider?->name,
            'decision_note' => $this->decision_note,
            'decided_at' => $this->decided_at?->toISOString(),
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }
}
