<?php

namespace App\Models;

use App\Support\PaneTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One discovered object of a managed database (the schema, a table, a column
 * or a relationship), as Pane last saw it, plus the human description Pane
 * owns. The managed database stays authoritative for structure: refreshes
 * update structure and status but never the description.
 *
 * @property string $catalog_object_id
 * @property string $organization_id
 * @property string $connection_id
 * @property string|null $parent_id
 * @property string $object_key
 * @property string $kind
 * @property int $sort_rank
 * @property string $name
 * @property string $status
 * @property array<string, mixed> $structure
 * @property list<string> $issues
 * @property string|null $description
 * @property bool $crud_enabled
 * @property int $revision
 * @property Carbon|null $missing_since
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class CatalogObject extends Model
{
    public const string KIND_SCHEMA = 'schema';

    public const string KIND_TABLE = 'table';

    public const string KIND_COLUMN = 'column';

    public const string KIND_RELATIONSHIP = 'relationship';

    /** Kinds in listing order. */
    public const array KINDS = [self::KIND_SCHEMA, self::KIND_TABLE, self::KIND_COLUMN, self::KIND_RELATIONSHIP];

    public const string STATUS_AVAILABLE = 'available';

    public const string STATUS_MISSING = 'missing';

    public const string STATUS_INCOMPATIBLE = 'incompatible';

    protected $primaryKey = 'catalog_object_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'catalog_object_id', 'organization_id', 'connection_id', 'parent_id', 'object_key', 'kind', 'sort_rank',
        'name', 'status', 'structure', 'issues', 'description', 'crud_enabled', 'revision', 'missing_since',
    ];

    protected $casts = [
        'sort_rank' => 'integer',
        'structure' => 'array',
        'issues' => 'array',
        'crud_enabled' => 'boolean',
        'revision' => 'integer',
        'missing_since' => 'datetime',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->table = PaneTable::name(PaneTable::CATALOG_OBJECTS);
    }

    protected static function booted(): void
    {
        static::creating(function (CatalogObject $object): void {
            if (blank($object->catalog_object_id)) {
                $object->catalog_object_id = (string) Str::uuid();
            }
        });
    }

    /** Identity of an object inside one connection: its kind plus the exact identifiers that name it. */
    public static function keyFor(string $kind, string ...$identifiers): string
    {
        return hash('sha256', implode("\0", [$kind, ...$identifiers]));
    }

    /**
     * @return BelongsTo<ManagedConnection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(ManagedConnection::class, 'connection_id', 'connection_id');
    }

    /**
     * Phase one: only a table an organization administrator enabled, and that
     * still meets the CRUD table contract, may be used for row CRUD.
     */
    public function isCrudCandidate(): bool
    {
        return $this->kind === self::KIND_TABLE && $this->status === self::STATUS_AVAILABLE && $this->crud_enabled;
    }

    public function versionTag(): string
    {
        return 'r'.$this->revision;
    }
}
