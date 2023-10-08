<?php

namespace App\Helpers;

use App\Exceptions\PaneException;
use Illuminate\Support\Str;

trait StoryHelper
{
    /**
     * loads the requested action class and returns it
     *
     * @param string $name
     * @return object|mixed
     * @throws PaneException
     */
    public function loadAction(string $name): object
    {
        return $this->loadObject($name, 'Actions');
    }

    /**
     * loads the requested story class and returns it
     *
     * @param string $name
     * @return object|mixed
     * @throws PaneException
     */
    public function loadStory(string $name): object
    {
        return $this->loadObject($name, 'Stories');
    }

    /**
     * loads the requested class using the name and the type
     *
     * @param string $name
     * @param string $type
     * @return mixed
     * @throws PaneException
     */
    private function loadObject(string $name, string $type): object
    {
        $class = "App\\$type\\".$this->capitalCase($name);
        if (class_exists($class)) {
            return new $class();
        }
        $objectName = Str::singular($type);
        throw new PaneException("$objectName not found ($objectName: $name)", 404);
    }
}
