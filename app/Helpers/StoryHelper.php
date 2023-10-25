<?php

namespace App\Helpers;

use App\Exceptions\SystemException;
use Illuminate\Support\Str;

trait StoryHelper
{
    use StringHelper;

    /**
     * loads the requested action class and returns it
     *
     * @param string $name
     * @return object|mixed
     * @throws SystemException
     */
    public function loadAction(string $name): object
    {
        return $this->loadObject("{$name}Action", 'Actions');
    }

    /**
     * loads the requested story class and returns it
     *
     * @param string $name
     * @return object|mixed
     * @throws SystemException
     */
    public function loadStory(string $name): object
    {
        return $this->loadObject("{$name}Story", 'Stories');
    }

    /**
     * loads the requested class using the name and the type
     *
     * @param string $name
     * @param string $type
     * @return mixed
     * @throws SystemException
     */
    private function loadObject(string $name, string $type): object
    {
        $class = "App\\$type\\".$this->capitalCase($name);
        if (class_exists($class)) {
            return new $class();
        }
        $objectName = Str::singular($type);
        throw new SystemException("$objectName not found ($objectName: $name)", 404);
    }
}
