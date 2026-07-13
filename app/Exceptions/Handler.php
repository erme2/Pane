<?php

namespace App\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Response;
use Illuminate\Session\TokenMismatchException;
use Throwable;

class Handler extends ExceptionHandler
{
    private const HTTP_PAGE_EXPIRED = 419;

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
     * @return Response
     */
    public function render($request, Throwable $e)
    {
        $exceptionCode = $e->getCode();

        if ($e instanceof AuthenticationException) {
            $statusID = Response::HTTP_UNAUTHORIZED;
        } elseif ($e instanceof TokenMismatchException) {
            $statusID = self::HTTP_PAGE_EXPIRED;
        } elseif (is_int($exceptionCode) && $exceptionCode >= Response::HTTP_BAD_REQUEST && $exceptionCode <= 600) {
            $statusID = $exceptionCode;
        } else {
            $statusID = Response::HTTP_INTERNAL_SERVER_ERROR;
        }

        $content = [
            'status' => $statusID === self::HTTP_PAGE_EXPIRED ? 'Page Expired' : Response::$statusTexts[$statusID],
            'data' => [],
        ];

        switch ($e) {
            case $e instanceof ValidationException:
                $content['data']['errors'] = $e->getErrors();
                break;
            case $e instanceof SystemException:
            default:
                $content['data']['message'] = $e->getMessage();
                if (config('app.debug') && config('app.env') !== 'production') {
                    $content['data']['file'] = $e->getFile();
                    $content['data']['line'] = $e->getLine();
                    $content['data']['trace'] = $e->getTrace();
                }
                break;
        }

        return new Response($content, $statusID);
    }
}
