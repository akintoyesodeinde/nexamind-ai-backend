<?php

declare(strict_types=1);

namespace App\Domain\Audit\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string|null $tenant_id
 * @property string|null $actor_user_id
 * @property string $event_type
 * @property string $severity
 * @property string|null $request_id
 * @property string|null $ip_address
 * @property string $summary
 * @property array<string, mixed>|null $details_json
 * @property Carbon $created_at
 */
class SecurityEvent extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'security_events';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'actor_user_id',
        'event_type',
        'severity',
        'request_id',
        'ip_address',
        'summary',
        'details_json',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'details_json' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
