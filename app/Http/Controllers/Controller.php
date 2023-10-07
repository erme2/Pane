<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Stories\StoryPlot;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller as BaseController;

class Controller extends BaseController
{
    use ResponseHelper;

    public function index(): Response
    {
        $response = new StoryPlot();
        $response->setStatus(Response::HTTP_OK);
        $response->data = [
            'message' => 'Welcome to Pane RestAPI',
        ];
        return $this->success($response);
    }
}
