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
 * @property string $aggregate_type
 * @property string $aggregate_id
 * @property string $event_type
 * @property string $dedup_key
 * @property array<string, mixed> $payload_json
 * @property string $status
 * @property Carbon|null $processed_at
 * @property Carbon $created_at
 */
class OutboxEvent extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'outbox_events';

    public $incrementing = false;

    protected $keyType = 'string';

    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'aggregate_type',
        'aggregate_id',
        'event_type',
        'dedup_key',
        'payload_json',
        'status',
        'processed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload_json' => 'array',
            'processed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
