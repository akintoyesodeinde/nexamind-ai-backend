<?php

declare(strict_types=1);

namespace App\Domain\Documents\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $document_id
 * @property string $principal_type
 * @property string $principal_id
 * @property string $permission_level
 * @property string|null $granted_by
 * @property Carbon $created_at
 */
class DocumentPermission extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'document_permissions';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'document_id',
        'principal_type',
        'principal_id',
        'permission_level',
        'granted_by',
    ];
}
