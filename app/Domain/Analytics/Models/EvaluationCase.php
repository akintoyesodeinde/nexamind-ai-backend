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
 * @property string $category
 * @property string $question
 * @property array<int, string>|null $expected_keywords_json
 * @property bool $expect_abstention
 * @property bool $require_citations
 * @property Carbon $created_at
 */
class EvaluationCase extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'evaluation_cases';

    public $incrementing = false;

    protected $keyType = 'string';

    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'evaluation_dataset_id',
        'category',
        'question',
        'expected_keywords_json',
        'expect_abstention',
        'require_citations',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expected_keywords_json' => 'array',
            'expect_abstention' => 'boolean',
            'require_citations' => 'boolean',
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
            'category' => $this->category,
            'question' => $this->question,
            'expected_keywords' => $this->expected_keywords_json ?? [],
            'expect_abstention' => (bool) $this->expect_abstention,
            'require_citations' => (bool) $this->require_citations,
        ];
    }
}
