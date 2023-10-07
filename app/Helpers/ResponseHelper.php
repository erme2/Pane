<?php

namespace App\Helpers;

use App\Stories\StoryPlot;
use Illuminate\Http\Response;

trait ResponseHelper
{
    const CONTENT_TYPES = [
        'json' => 'application/json',
//        'xml' => 'application/xml',
//        'html' => 'text/html',
    ];

    private Response $response;

    public function __construct()
    {
        $this->response = new Response();
    }

    public function getStatusText(int $status): string
    {
        return Response::$statusTexts[$status] ?? '';
    }

    public function success(StoryPlot $storyPlot): Response
    {
        $this->response
            ->setStatusCode($storyPlot->getStatus())
            ->header('Content-Type', $storyPlot->getContentType())
            ->setContent([
                'status' => $this->getStatusText($storyPlot->getStatus()),
                'data' => $storyPlot->data,
            ]);

        return $this->response;
    }

    public function error(\Throwable $exception): Response
    {
        $errorData = [
            'message' => $exception->getMessage(),
        ];
        $errorData['trace'] = $exception->getTrace();
        $this->response
            ->setStatusCode(Response::HTTP_INTERNAL_SERVER_ERROR)
            ->header('Content-Type', self::CONTENT_TYPES['json'])
            ->setContent([
                'status' => $this->getStatusText(Response::HTTP_INTERNAL_SERVER_ERROR),
                'data' => $errorData,
            ]);
        return $this->response;
    }
}
