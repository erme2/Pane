<?php

namespace App\Helpers;

use App\Exceptions\SystemException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Helper for App/Stories.
 *
 * @package App\Helpers
 */

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
    public function loadStory(Request $request, string $name): object
    {
        return $this->loadObject("{$name}Story", 'Stories', $request);
    }

    /**
     * loads the requested class using the name and the type
     *
     * @param string $name
     * @param string $type
     * @return mixed
     * @throws SystemException
     */
    private function loadObject(string $name, string $type, $argument = null): object
    {
        $class = "App\\$type\\".$this->capitalCase($name);
        if (class_exists($class)) {
            return new $class($argument);
        }
        $objectName = Str::singular($type);
        throw new SystemException("$objectName not found ($objectName: $name)", 404);
    }
}
