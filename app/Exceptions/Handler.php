<?php

namespace App\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Exception\RequestExceptionInterface;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
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
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function render($request, Throwable $e)
    {
        if ($this->isVersionedApiRequest($request)) {
            return $this->versionedErrorResponse($request, $e);
        }

        $exceptionCode = $e->getCode();

        if ($e instanceof AuthenticationException) {
            $statusID = Response::HTTP_UNAUTHORIZED;
        } elseif ($e instanceof TokenMismatchException) {
            $statusID = self::HTTP_PAGE_EXPIRED;
        } elseif ($e instanceof RequestExceptionInterface) {
            $statusID = Response::HTTP_BAD_REQUEST;
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

    private function isVersionedApiRequest(mixed $request): bool
    {
        return $request instanceof Request && $request->is('api/v1/*');
    }

    private function versionedErrorResponse(Request $request, Throwable $e): JsonResponse
    {
        [$status, $code, $message] = match (true) {
            $e instanceof AuthenticationException => [
                Response::HTTP_UNAUTHORIZED,
                'authentication_required',
                'Authentication is required.',
            ],
            $e instanceof TokenMismatchException => [
                Response::HTTP_FORBIDDEN,
                'csrf_failed',
                'CSRF validation failed.',
            ],
            $e instanceof RequestExceptionInterface => [
                Response::HTTP_BAD_REQUEST,
                'invalid_request',
                $e->getMessage() ?: 'The request is invalid.',
            ],
            $e instanceof HttpExceptionInterface => $this->versionedHttpError($e),
            default => $this->versionedDefaultError($e),
        };
        $requestId = $this->requestId($request);

        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
                'request_id' => $requestId,
            ],
        ], $status)->header('X-Request-Id', $requestId);
    }

    /**
     * @return array{0: int, 1: string, 2: string}
     */
    private function versionedHttpError(HttpExceptionInterface $e): array
    {
        return match ($e->getStatusCode()) {
            Response::HTTP_BAD_REQUEST => [
                Response::HTTP_BAD_REQUEST,
                'invalid_request',
                'The request is invalid.',
            ],
            Response::HTTP_NOT_FOUND => [
                Response::HTTP_NOT_FOUND,
                'resource_not_found',
                'The requested resource was not found.',
            ],
            Response::HTTP_TOO_MANY_REQUESTS => [
                Response::HTTP_TOO_MANY_REQUESTS,
                'rate_limited',
                'Too many requests.',
            ],
            Response::HTTP_SERVICE_UNAVAILABLE => [
                Response::HTTP_SERVICE_UNAVAILABLE,
                'dependency_unavailable',
                'A dependency is unavailable.',
            ],
            default => $this->versionedDefaultError($e),
        };
    }

    /**
     * @return array{0: int, 1: string, 2: string}
     */
    private function versionedDefaultError(Throwable $e): array
    {
        $exceptionCode = $e->getCode();

        if ($exceptionCode === Response::HTTP_SERVICE_UNAVAILABLE) {
            return [Response::HTTP_SERVICE_UNAVAILABLE, 'dependency_unavailable', 'A dependency is unavailable.'];
        }

        return [Response::HTTP_INTERNAL_SERVER_ERROR, 'internal_error', 'An internal error occurred.'];
    }

    private function requestId(Request $request): string
    {
        $requestId = $request->header('X-Request-Id');

        return is_string($requestId) && Str::isUuid($requestId)
            ? $requestId
            : (string) Str::uuid();
    }
}
