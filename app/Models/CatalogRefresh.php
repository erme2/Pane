<?php

namespace App\Models;

use App\Support\PaneTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One catalog discovery run for a managed connection.
 *
 * @property string $catalog_refresh_id
 * @property string $organization_id
 * @property string $connection_id
 * @property string $status
 * @property array{added: int, changed: int, missing: int, unchanged: int}|null $counts
 * @property string|null $failure_reason
 * @property int|null $requested_by_user_id
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class CatalogRefresh extends Model
{
    public const string STATUS_QUEUED = 'queued';

    public const string STATUS_RUNNING = 'running';

    public const string STATUS_COMPLETED = 'completed';

    public const string STATUS_FAILED = 'failed';

    protected $primaryKey = 'catalog_refresh_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'catalog_refresh_id', 'organization_id', 'connection_id', 'status', 'counts', 'failure_reason',
        'requested_by_user_id', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'counts' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->table = PaneTable::name(PaneTable::CATALOG_REFRESHES);
    }

    protected static function booted(): void
    {
        static::creating(function (CatalogRefresh $refresh): void {
            if (blank($refresh->catalog_refresh_id)) {
                $refresh->catalog_refresh_id = (string) Str::uuid();
            }
        });
    }
}
