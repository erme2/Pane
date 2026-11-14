<?php

namespace App\Models\Maps;

use App\Support\PaneTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Where mapped tables live: the install's own database (`install`) or, later,
 * an external one. Change it through MapStore so every save is versioned.
 *
 * @property int $map_source_id
 * @property string $name
 * @property string $kind
 * @property string|null $description
 * @property int $version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class MapSource extends Model
{
    protected $primaryKey = 'map_source_id';

    protected $fillable = ['name', 'kind', 'description', 'version'];

    protected $casts = ['version' => 'integer'];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->table = PaneTable::name(PaneTable::MAPS_SOURCES);
    }

    /** @return HasMany<MapTable, $this> */
    public function tables(): HasMany
    {
        return $this->hasMany(MapTable::class, 'map_source_id', 'map_source_id');
    }
}
