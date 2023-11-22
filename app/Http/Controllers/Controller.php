<?php

namespace App\Http\Controllers;

use App\Exceptions\SystemException;
use App\Helpers\ResponseHelper;
use App\Helpers\StoryHelper;
use App\Stories\StoryPlot;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller as BaseController;

class Controller extends BaseController
{
    use  ResponseHelper, StoryHelper;

    /**
     * just returns a welcome message
     *
     * @return Response
     * @throws SystemException
     */
    public function index(): Response
    {
        $response = new StoryPlot();
        $response->setStatus(Response::HTTP_OK);
        $response->data = [
            'message' => 'Welcome to Pane RestAPI',
        ];
        return $this->success($response);
    }

    /**
     * just runs the requested story
     *
     * @param Request $request
     * @param string $story
     * @param string $subject
     * @param $key
     * @return Response
     * @throws SystemException
     */
    public function runStory(Request $request, string $story, string $subject, $key = null): Response
    {
        $story = $this->loadStory($request, $story);
        return $this->success($story->run($subject, $key));
    }
}
