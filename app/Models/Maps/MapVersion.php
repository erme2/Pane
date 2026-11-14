<?php

namespace App\Models\Maps;

use App\Support\PaneTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One saved version of a source or table map: the full snapshot, the diff
 * against the previous version, who saved it and, for a restore, which
 * version it copied. Versions are never edited or deleted.
 *
 * @property int $map_version_id
 * @property string $subject_type
 * @property int $subject_id
 * @property int $version
 * @property array<string, mixed> $snapshot
 * @property array<string, mixed>|null $diff
 * @property string $author
 * @property int|null $restored_from
 * @property Carbon|null $created_at
 */
class MapVersion extends Model
{
    public const string SUBJECT_SOURCE = 'source';

    public const string SUBJECT_TABLE = 'table';

    public const UPDATED_AT = null;

    protected $primaryKey = 'map_version_id';

    protected $fillable = ['subject_type', 'subject_id', 'version', 'snapshot', 'diff', 'author', 'restored_from'];

    protected $casts = [
        'subject_id' => 'integer',
        'version' => 'integer',
        'snapshot' => 'array',
        'diff' => 'array',
        'restored_from' => 'integer',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->table = PaneTable::name(PaneTable::MAPS_VERSIONS);
    }
}
