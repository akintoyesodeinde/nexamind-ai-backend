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
 * @property string|null $created_by
 * @property string $name
 * @property string|null $description
 * @property array<int, string>|null $knowledge_base_ids_json
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class EvaluationDataset extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'evaluation_datasets';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'created_by',
        'name',
        'description',
        'knowledge_base_ids_json',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'knowledge_base_ids_json' => 'array',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(bool $includeCases = false): array
    {
        $casesCount = EvaluationCase::query()
            ->where('tenant_id', $this->tenant_id)
            ->where('evaluation_dataset_id', $this->id)
            ->count();

        $data = [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'knowledge_base_ids' => $this->knowledge_base_ids_json ?? [],
            'cases_count' => $casesCount,
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];

        if ($includeCases) {
            $data['cases'] = EvaluationCase::query()
                ->where('tenant_id', $this->tenant_id)
                ->where('evaluation_dataset_id', $this->id)
                ->orderBy('created_at')
                ->get()
                ->map(static fn (EvaluationCase $c): array => $c->toApiArray())
                ->values()
                ->all();
        }

        return $data;
    }
}
