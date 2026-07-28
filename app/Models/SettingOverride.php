<?php

namespace App\Models;

use App\Support\PaneTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * @property string $setting_override_id
 * @property string $setting_key
 * @property string $scope
 * @property string $scope_id
 * @property mixed $value
 * @property int $default_version
 * @property int|null $updated_by_user_id
 */
class SettingOverride extends Model
{
    protected $primaryKey = 'setting_override_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'setting_override_id',
        'setting_key',
        'scope',
        'scope_id',
        'value',
        'default_version',
        'updated_by_user_id',
    ];

    protected $casts = [
        'default_version' => 'integer',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->table = PaneTable::name(PaneTable::SETTING_OVERRIDES);
    }

    protected static function booted(): void
    {
        static::creating(function (SettingOverride $override): void {
            if (blank($override->setting_override_id)) {
                $override->setting_override_id = (string) Str::uuid();
            }
        });
    }

    public function getValueAttribute(?string $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return json_decode($value, true, 512, JSON_THROW_ON_ERROR);
    }

    public function setValueAttribute(mixed $value): void
    {
        $this->attributes['value'] = json_encode($value, JSON_THROW_ON_ERROR);
    }
}
