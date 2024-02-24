<?php

namespace App\Helpers;

use App\Models\Field;

trait ModelHelper
{
    /**
     * Get the primary key for the model.
     *
     * @return string|array
     */
    public function getPrimaryKey(): array|string|null
    {
        $return = [];
        foreach ((new Field())->getFields($this->getMapName()) as $field) {
            if ($field->primary) {
                $return[] = $field->name;
            }
        }

        if ($return) {
            if (count($return) > 1) {
                return $return;
            }
            return $return[0];
        }
        return null;
    }
}
