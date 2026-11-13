<?php

namespace App\Models\Maps;

use App\Exceptions\Maps\SystemMapProtectedException;
use App\Support\Maps\MapProtection;
use App\Support\PaneTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The map of one table: what Pane and Latte expose of it, with its optional
 * `ui` part. Change it through MapStore so every save is versioned. System
 * maps (MapProtection) refuse every save and delete, including a change of
 * sql_name into or out of the system prefix.
 *
 * @property int $map_table_id
 * @property int $map_source_id
 * @property string $name
 * @property string|null $sql_name
 * @property string|null $description
 * @property array<mixed>|null $ui
 * @property int $version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class MapTable extends Model
{
    protected $primaryKey = 'map_table_id';

    protected $fillable = ['map_source_id', 'name', 'sql_name', 'description', 'ui', 'version'];

    protected $casts = ['ui' => 'array', 'version' => 'integer'];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->table = PaneTable::name(PaneTable::MAPS_TABLES);
    }

    protected static function booted(): void
    {
        $guard = function (MapTable $table): void {
            if ($table->isSystem()) {
                throw SystemMapProtectedException::forTable($table->name, $table->sql_name);
            }
        };
        static::saving($guard);
        static::deleting($guard);
    }

    public function isSystem(): bool
    {
        $original = $this->getOriginal('sql_name');

        return MapProtection::isSystemTable($this->sql_name)
            || (is_string($original) && MapProtection::isSystemTable($original));
    }

    /** @return BelongsTo<MapSource, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(MapSource::class, 'map_source_id', 'map_source_id');
    }

    /** @return HasMany<MapField, $this> */
    public function fields(): HasMany
    {
        return $this->hasMany(MapField::class, 'map_table_id', 'map_table_id')->orderBy('position');
    }
}
