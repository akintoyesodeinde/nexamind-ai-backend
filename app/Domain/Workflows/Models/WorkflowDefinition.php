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
 * @property string|null $agent_id
 * @property string|null $created_by
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string $workflow_type
 * @property array<int, array<string, mixed>> $steps_definition_json
 * @property string $approver_role
 * @property bool $is_active
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class WorkflowDefinition extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'workflow_definitions';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'agent_id',
        'created_by',
        'name',
        'slug',
        'description',
        'workflow_type',
        'steps_definition_json',
        'approver_role',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'steps_definition_json' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(?int $runsCount = null): array
    {
        $count = $runsCount ?? WorkflowRun::query()
            ->where('tenant_id', $this->tenant_id)
            ->where('workflow_definition_id', $this->id)
            ->count();

        return [
            'id' => $this->id,
            'agent_id' => $this->agent_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'workflow_type' => $this->workflow_type,
            'steps_definition' => $this->steps_definition_json ?? [],
            'approver_role' => $this->approver_role,
            'is_active' => (bool) $this->is_active,
            'runs_count' => $count,
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }
}
