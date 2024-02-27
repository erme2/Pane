<?php

namespace App\Models;

use App\Helpers\CoreHelper;
use App\Helpers\ModelHelper;
use Illuminate\Database\Eloquent\Model;

class AbstractModel extends Model
{
    use CoreHelper, ModelHelper;

    private string $mapName;
    public $timestamps = false;

    public function newInstance($attributes = [], $exists = false)
    {
        $return = parent::newInstance($attributes, $exists);
        $return->setKeyName($this->getKeyName());
        return $return;
    }

    /**
     * returns the table name from the tables map
     *
     * @return string
     */
    public function getMapName(): string
    {
        return $this->mapName;
    }

    /**
     * sets the table name from the tables map
     *
     * @param string $subject
     * @return $this
     */
    public function setMapName(string $mapName): AbstractModel
    {
        $this->mapName = $mapName;
        return $this;
    }
}
