<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $default_chat_model
 * @property string $default_embedding_model
 * @property int $max_upload_mb
 * @property int $monthly_token_budget
 * @property string $monthly_cost_budget_usd
 * @property array<int, string> $allowed_file_types_json
 * @property int $retention_days
 * @property bool $require_approval_for_external_actions
 * @property Carbon $updated_at
 */
class TenantSetting extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'tenant_settings';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'default_chat_model',
        'default_embedding_model',
        'max_upload_mb',
        'monthly_token_budget',
        'monthly_cost_budget_usd',
        'allowed_file_types_json',
        'retention_days',
        'require_approval_for_external_actions',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'max_upload_mb' => 'integer',
            'monthly_token_budget' => 'integer',
            'monthly_cost_budget_usd' => 'decimal:6',
            'allowed_file_types_json' => 'array',
            'retention_days' => 'integer',
            'require_approval_for_external_actions' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'default_chat_model' => $this->default_chat_model,
            'default_embedding_model' => $this->default_embedding_model,
            'max_upload_mb' => $this->max_upload_mb,
            'monthly_token_budget' => $this->monthly_token_budget,
            'monthly_cost_budget_usd' => number_format((float) $this->monthly_cost_budget_usd, 6, '.', ''),
            'allowed_file_types' => $this->allowed_file_types_json ?? ['pdf', 'docx', 'txt', 'md', 'csv'],
            'retention_days' => $this->retention_days,
            'require_approval_for_external_actions' => $this->require_approval_for_external_actions,
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }
}
