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
            'status' => Response::$statusTexts[$e->getCode()] ?? 'Error',
            'data' => [],
        ];
        switch ($e) {
            case $e instanceof ValidationException:
                $content['data']['errors'] = $e->getErrors();
                break;
            case $e instanceof SystemException:
            default:
                $content['data']['message'] = $e->getMessage();
                if (env('APP_DEBUG')) {
                    $content['data']['file'] = $e->getFile();
                    $content['data']['line'] = $e->getLine();
                    $content['data']['trace'] = $e->getTrace();
                }
                break;
        }

        $response = new Response();
        $response->setStatusCode($e->getCode());
        $response->setContent($content);
        return $response;
    }
}
