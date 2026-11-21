<?php

namespace App\Http\Controllers;

use App\Exceptions\Maps\InvalidMapException;
use App\Exceptions\Maps\MapNotFoundException;
use App\Exceptions\Maps\MapVersionConflictException;
use App\Exceptions\Maps\SettingNotEditableException;
use App\Models\User;
use App\Services\AppSettings;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * v1 app settings API (Pane#158): administrators list the settings, set a value
 * (`If-Match` on the value's version) and upload an image. Public settings reach
 * every signed-in user through `GET /maps`, not here. Users who aren't
 * administrators get 403.
 */
class SettingsController extends Controller
{
    public function __construct(private readonly AppSettings $settings) {}

    public function list(Request $request): JsonResponse
    {
        return $this->guarded($request, fn (User $actor, string $requestId): JsonResponse => response()->json([
            'data' => array_map(fn (array $setting): array => $this->resource($setting), $this->settings->all()),
            'meta' => ['request_id' => $requestId],
        ])->header('X-Request-Id', $requestId)->header('Cache-Control', 'no-store'));
    }

    public function show(Request $request, string $key): JsonResponse
    {
        return $this->guarded($request, fn (User $actor, string $requestId): JsonResponse => $this->ok($requestId, $this->settings->find($key)));
    }

    public function update(Request $request, string $key): JsonResponse
    {
        return $this->guarded($request, function (User $actor, string $requestId) use ($request, $key): JsonResponse {
            $expected = $this->expectedVersion($request, $key);
            if ($expected instanceof JsonResponse) {
                return $expected;
            }
            $body = $request->json()->all();
            if (! array_key_exists('value', $body)) {
                throw new InvalidMapException(['value' => 'Required.']);
            }

            return $this->ok($requestId, $this->settings->set($actor, $key, $body['value'], $expected, $requestId));
        });
    }

    public function upload(Request $request, string $key): JsonResponse
    {
        return $this->guarded($request, function (User $actor, string $requestId) use ($request, $key): JsonResponse {
            $expected = $this->expectedVersion($request, $key);
            if ($expected instanceof JsonResponse) {
                return $expected;
            }
            $file = $request->file('file');
            if (! $file instanceof UploadedFile) {
                throw new InvalidMapException(['file' => 'A file is required (multipart field "file").']);
            }

            return $this->ok($requestId, $this->settings->upload($actor, $key, $file, $expected, $requestId));
        });
    }

    /**
     * @param  Closure(User, string): JsonResponse  $action
     */
    private function guarded(Request $request, Closure $action): JsonResponse
    {
        $requestId = $this->requestId($request);
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->isPaneAdministrator()) {
            return $this->fail($requestId, 'permission_denied', 'Only administrators can read or edit settings.', Response::HTTP_FORBIDDEN);
        }

        try {
            return $action($actor, $requestId);
        } catch (MapNotFoundException) {
            return $this->fail($requestId, 'resource_not_found', 'The requested resource was not found.', Response::HTTP_NOT_FOUND);
        } catch (SettingNotEditableException $exception) {
            return $this->fail($requestId, 'permission_denied', $exception->getMessage(), Response::HTTP_FORBIDDEN);
        } catch (MapVersionConflictException) {
            return $this->fail($requestId, 'version_conflict', 'The resource version does not match the If-Match header.', Response::HTTP_PRECONDITION_FAILED);
        } catch (InvalidMapException $exception) {
            return $this->fail($requestId, 'validation_failed', 'The setting is not valid.', Response::HTTP_UNPROCESSABLE_ENTITY, $exception->errors);
        }
    }

    /** The version in If-Match, or the error answer; the setting must be declared. */
    private function expectedVersion(Request $request, string $key): int|JsonResponse
    {
        $requestId = $this->requestId($request);
        $this->settings->find($key);
        $ifMatch = $request->header('If-Match');
        if ($ifMatch === null || $ifMatch === '') {
            return $this->fail($requestId, 'precondition_required', 'The If-Match header is required for this operation.', Response::HTTP_PRECONDITION_REQUIRED);
        }
        if (! preg_match('/\A"[A-Za-z0-9._~-]{1,128}"\z/', $ifMatch)) {
            return $this->fail($requestId, 'invalid_request', 'The If-Match header must be a quoted strong ETag.', Response::HTTP_BAD_REQUEST);
        }
        $version = $this->settings->valueVersion($key);
        if ($ifMatch !== self::etag($key, $version)) {
            return $this->fail($requestId, 'version_conflict', 'The resource version does not match the If-Match header.', Response::HTTP_PRECONDITION_FAILED);
        }

        return $version;
    }

    private static function etag(string $key, int $version): string
    {
        return '"setting-'.$key.'-'.$version.'"';
    }

    /**
     * @param  array<string, mixed>  $setting
     * @return array<string, mixed>
     */
    private function resource(array $setting): array
    {
        return ['id' => $setting['key'], 'type' => 'setting', 'attributes' => $setting];
    }

    /** @param  array<string, mixed>  $setting */
    private function ok(string $requestId, array $setting): JsonResponse
    {
        return response()->json([
            'data' => $this->resource($setting),
            'meta' => ['request_id' => $requestId],
        ])->header('X-Request-Id', $requestId)
            ->header('ETag', self::etag($setting['key'], $setting['version']))
            ->header('Cache-Control', 'no-store');
    }

    /** @param  array<string, string>|null  $fields */
    private function fail(string $requestId, string $code, string $message, int $status, ?array $fields = null): JsonResponse
    {
        return response()->json([
            'error' => array_filter([
                'code' => $code,
                'message' => $message,
                'fields' => $fields,
                'request_id' => $requestId,
            ], fn ($value) => $value !== null),
        ], $status)->header('X-Request-Id', $requestId);
    }

    private function requestId(Request $request): string
    {
        $requestId = $request->header('X-Request-Id');

        return is_string($requestId) && Str::isUuid($requestId) ? $requestId : (string) Str::uuid();
    }
}
