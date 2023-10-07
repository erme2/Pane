<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\App;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });
    }

    public function render($request, Throwable $e)
    {
        $content = [
            'status' => Response::$statusTexts[Response::HTTP_INTERNAL_SERVER_ERROR] ?? '',
            'data' => [
                'message' => $e->getMessage(),
            ],
        ];
        if (env('APP_DEBUG')) {
            $content['data']['file'] = $e->getFile();
            $content['data']['line'] = $e->getLine();
            $content['data']['trace'] = $e->getTrace();
        }

        $response = new Response();
        $response->setStatusCode(Response::HTTP_INTERNAL_SERVER_ERROR);
        $response->setContent($content);
        return $response;
    }
}
