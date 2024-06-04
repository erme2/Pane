<?php

namespace App\Helpers;

use App\Stories\StoryPlot;
use Illuminate\Http\Response;

/**
 * Trait ResponseHelper
 * This trait is used to build a standard response for the API.
 *
 * @package App\Helpers
 */
trait ResponseHelper
{
    const CONTENT_TYPES = [
        'json' => 'application/json',
//        'xml' => 'application/xml',
//        'html' => 'text/html',
    ];

    private Response $response;

    /**
     * If your class is using this trait, you will have a private
     * property called $response you will be able to use to build
     * and return a response.
     *
     *  @return void
     */
    public function __construct()
    {
        $this->response = new Response();
    }

    /**
     * Returns the response object.
     *
     * @return Response
     */
    public function getResponse(): Response
    {
        return $this->response;
    }

    /**
     * Returns the status text for the given status code.
     *
     * @param int $status
     * @return string
     */
    public function getStatusText(int $status): string
    {
        return Response::$statusTexts[$status] ?? '';
    }

    /**
     * Returns a standard success response.
     *
     * @param StoryPlot $storyPlot
     * @return Response
     */
    public function success(StoryPlot $storyPlot): Response
    {
        $this->response
            ->setStatusCode($storyPlot->getStatus() ?: Response::HTTP_OK)
            ->header('Content-Type', $storyPlot->getContentType())
            ->setContent([
                'status' => $this->getStatusText($storyPlot->getStatus()),
                'data' => $storyPlot->data,
            ]);

        return $this->response;
    }

    /**
     * Returns a standard error response.
     *
     * @param \Throwable $exception
     * @return Response
     */
    public function error(\Throwable $exception): Response
    {
        $statusID = $exception->getCode() >= Response::HTTP_BAD_REQUEST && $exception->getCode() <= 600 ?
            $exception->getCode() : Response::HTTP_INTERNAL_SERVER_ERROR;

        $errorData = [
            'message' => $exception->getMessage(),
        ];
        $errorData['trace'] = $exception->getTrace();
        $this->response
            ->setStatusCode($statusID)
            ->header('Content-Type', self::CONTENT_TYPES['json'])
            ->setContent([
                'status' => $this->getStatusText($statusID),
                'data' => $errorData,
            ]);
        return $this->response;
    }
}
