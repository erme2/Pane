<?php

namespace App\Models\Maps;

use App\Exceptions\Maps\SystemMapProtectedException;
use App\Support\PaneTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One field of a table map. A field of a system map can't be saved or deleted.
 * `computed` is stored only for now; the read and CRUD tickets evaluate it.
 *
 * @property int $map_field_id
 * @property int $map_table_id
 * @property string $name
 * @property string|null $sql_name
 * @property string $type
 * @property string|null $description
 * @property string|null $format
 * @property bool $primary
 * @property bool $index
 * @property bool $sortable
 * @property bool $nullable
 * @property mixed $default
 * @property list<array<string, mixed>>|null $validations
 * @property array<mixed>|null $computed
 * @property array<mixed>|null $ui
 * @property int $position
 */
class MapField extends Model
{
    public $timestamps = false;

    protected $primaryKey = 'map_field_id';

    protected $fillable = [
        'map_table_id', 'name', 'sql_name', 'type', 'description', 'format', 'primary', 'index', 'sortable',
        'nullable', 'default', 'validations', 'computed', 'ui', 'position',
    ];

    protected $casts = [
        'primary' => 'boolean',
        'index' => 'boolean',
        'sortable' => 'boolean',
        'nullable' => 'boolean',
        'default' => 'json',
        'validations' => 'array',
        'computed' => 'array',
        'ui' => 'array',
        'position' => 'integer',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->table = PaneTable::name(PaneTable::MAPS_FIELDS);
    }

    protected static function booted(): void
    {
        $guard = function (MapField $field): void {
            $table = MapTable::query()->find($field->map_table_id);
            if ($table !== null && $table->isSystem()) {
                throw SystemMapProtectedException::forTable($table->name, $table->sql_name);
            }
        };
        static::saving($guard);
        static::deleting($guard);
    }

    /** @return BelongsTo<MapTable, $this> */
    public function mapTable(): BelongsTo
    {
        return $this->belongsTo(MapTable::class, 'map_table_id', 'map_table_id');
    }
}
