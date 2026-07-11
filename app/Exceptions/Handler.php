<?php

namespace App\Exceptions;

use App\Helpers\ResponseHelper;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Response;
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

    /**
     * Returns an api response that will contain all the information about the error/s.
     *
     * @param $request
     * @param Throwable $e
     * @return Response
     */
    public function render($request, Throwable $e)
    {
        $exceptionCode = $e->getCode();

        $statusID = $e instanceof AuthenticationException
            ? Response::HTTP_UNAUTHORIZED
            : (is_int($exceptionCode) && $exceptionCode >= Response::HTTP_BAD_REQUEST && $exceptionCode <= 600
                ? $exceptionCode
                : Response::HTTP_INTERNAL_SERVER_ERROR);
        $content = [
            'status' => Response::$statusTexts[$statusID],
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

        return new Response($content, $statusID);
    }
}
