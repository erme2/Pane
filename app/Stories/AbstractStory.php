<?php

namespace App\Stories;

use App\Exceptions\SystemException;
use App\Helpers\StoryHelper;
use App\Helpers\StringHelper;
use Illuminate\Http\Request;

/**
 * Class AbstractStory
 * this class is the base class for all stories, it contains the basic logic of the story
 *
 * @package App\Stories
 */

abstract class AbstractStory
{
    use StringHelper, StoryHelper;

    public array $actions = [];
    public StoryPlot $plot;

    /**
     * Every story will have a story plot on __construct
     *
     * @param Request $request
     */
    public function __construct(Request $request)
    {
        $this->plot = new StoryPlot();
        $this->plot->setRequestData($request);
    }

    /**
     * Loads and execute the actions of the story
     *
     * @param string $subject
     * @param $key
     * @return StoryPlot
     * @throws SystemException
     */
    public function run(string $subject, $key = null): StoryPlot
    {
        foreach ($this->actions as $actionName) {
            $action = $this->loadAction($actionName);
            $this->plot = $action->exec($subject, $this->plot, $key);
        }
        return $this->plot;
    }
}
