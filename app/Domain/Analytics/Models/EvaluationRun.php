<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $evaluation_dataset_id
 * @property string|null $triggered_by
 * @property string $model_alias
 * @property string $status
 * @property int $total_cases
 * @property int $passed_cases
 * @property string $citation_correctness_pct
 * @property string $groundedness_score_pct
 * @property string $abstention_accuracy_pct
 * @property int $avg_latency_ms
 * @property string $total_cost_usd
 * @property array<int, array<string, mixed>>|null $case_results_json
 * @property Carbon $created_at
 * @property Carbon|null $completed_at
 */
class EvaluationRun extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'evaluation_runs';

    public $incrementing = false;

    protected $keyType = 'string';

    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'evaluation_dataset_id',
        'triggered_by',
        'model_alias',
        'status',
        'total_cases',
        'passed_cases',
        'citation_correctness_pct',
        'groundedness_score_pct',
        'abstention_accuracy_pct',
        'avg_latency_ms',
        'total_cost_usd',
        'case_results_json',
        'completed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'total_cases' => 'integer',
            'passed_cases' => 'integer',
            'avg_latency_ms' => 'integer',
            'case_results_json' => 'array',
            'created_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(bool $includeResults = false): array
    {
        /** @var EvaluationDataset|null $dataset */
        $dataset = EvaluationDataset::query()
            ->where('tenant_id', $this->tenant_id)
            ->where('id', $this->evaluation_dataset_id)
            ->first();

        $data = [
            'id' => $this->id,
            'dataset_id' => $this->evaluation_dataset_id,
            'dataset_name' => $dataset?->name,
            'model_alias' => $this->model_alias,
            'status' => $this->status,
            'total_cases' => (int) $this->total_cases,
            'passed_cases' => (int) $this->passed_cases,
            'citation_correctness_pct' => (float) $this->citation_correctness_pct,
            'groundedness_score_pct' => (float) $this->groundedness_score_pct,
            'abstention_accuracy_pct' => (float) $this->abstention_accuracy_pct,
            'meets_citation_target' => (float) $this->citation_correctness_pct >= 95.0,
            'avg_latency_ms' => (int) $this->avg_latency_ms,
            'total_cost_usd' => number_format((float) $this->total_cost_usd, 6, '.', ''),
            'created_at' => $this->created_at->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(),
        ];

        if ($includeResults) {
            $data['case_results'] = $this->case_results_json ?? [];
        }

        return $data;
    }
}
