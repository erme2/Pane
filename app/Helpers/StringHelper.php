<?php

namespace App\Helpers;

/**
 * Trait StringHelper
 * just some more string functions
 *
 * @package App\Helpers
 */

trait StringHelper
{
    /**
     * remove spaces and replaces dashes and underscores with spaces, then capitalizes the first letter of each word
     *
     * @param string $string
     * @return string
     */
    public function capitalCase(string $string): string
    {
        return str_replace([' '], [''], ucwords(str_replace(['-', '_'], [' ', ' '], $string)));
    }

    /**
     * another name for capital case
     *
     * @param string $string
     * @return string
     */
    public function pascalCase(string $string): string
    {
        return $this->capitalCase($string);
    }

    /**
     * like capital case but with a lower case first letter
     *
     * @param string $string
     * @return string
     */
    public function camelCase(string $string): string
    {
        return lcfirst($this->capitalCase($string));
    }
}
