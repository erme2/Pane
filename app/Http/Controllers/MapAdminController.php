<?php

namespace App\Http\Controllers;

use App\Exceptions\Maps\InvalidMapException;
use App\Exceptions\Maps\MapNotFoundException;
use App\Exceptions\Maps\MapVersionConflictException;
use App\Exceptions\Maps\SystemMapProtectedException;
use App\Models\Maps\MapVersion;
use App\Models\User;
use App\Services\MapAdminService;
use App\Services\MapReader;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * v1 maps admin API (Pane#157): administrators edit table maps, the menu and
 * the function switches. Every write needs `If-Match` (a table map that does
 * not exist yet needs `If-None-Match: *`), and none of them touches a table.
 * Users who aren't administrators get 403; system maps answer
 * 409 `system_map_protected`.
 */
class MapAdminController extends Controller
{
    public function __construct(private readonly MapAdminService $admin, private readonly MapReader $reader) {}

    public function saveTable(Request $request, string $source, string $table): JsonResponse
    {
        return $this->guarded($request, function (User $actor, string $requestId) use ($request, $source, $table): JsonResponse {
            $current = $this->currentTable($source, $table);
            $expected = $current === null ? $this->ifNoneMatchStar($request) : $this->tableVersion($request, $current);
            if ($expected instanceof JsonResponse) {
                return $expected;
            }
            $body = $request->json()->all();
            $this->admin->saveTable($actor, $source, $table, $body, $current === null ? null : $current['version'], $requestId);

            return $this->tableResponse($requestId, $source, $table, $current === null ? Response::HTTP_CREATED : Response::HTTP_OK);
        });
    }

    public function tableVersions(Request $request, string $source, string $table): JsonResponse
    {
        return $this->guarded($request, fn (User $actor, string $requestId): JsonResponse => $this->history($requestId, $this->admin->tableHistory($source, $table)));
    }

    public function restoreTable(Request $request, string $source, string $table, string $version): JsonResponse
    {
        return $this->guarded($request, function (User $actor, string $requestId) use ($request, $source, $table, $version): JsonResponse {
            if (! ctype_digit($version)) {
                throw new MapNotFoundException('Not a version.');
            }
            $current = $this->currentTable($source, $table) ?? throw new MapNotFoundException('Not mapped.');
            $expected = $this->tableVersion($request, $current);
            if ($expected instanceof JsonResponse) {
                return $expected;
            }
            $this->admin->restoreTable($actor, $source, $table, (int) $version, $expected, $requestId);

            return $this->tableResponse($requestId, $source, $table, Response::HTTP_OK);
        });
    }

    public function menu(Request $request): JsonResponse
    {
        return $this->guarded($request, fn (User $actor, string $requestId): JsonResponse => $this->menuResponse($requestId, Response::HTTP_OK));
    }

    public function saveMenu(Request $request): JsonResponse
    {
        return $this->guarded($request, function (User $actor, string $requestId) use ($request): JsonResponse {
            $expected = $this->menuVersion($request);
            if ($expected instanceof JsonResponse) {
                return $expected;
            }
            $this->admin->saveMenu($actor, $request->json()->all()['items'] ?? null, $expected, $requestId);

            return $this->menuResponse($requestId, Response::HTTP_OK);
        });
    }

    public function menuVersions(Request $request): JsonResponse
    {
        return $this->guarded($request, fn (User $actor, string $requestId): JsonResponse => $this->history($requestId, $this->admin->menuHistory()));
    }

    public function restoreMenu(Request $request, string $version): JsonResponse
    {
        return $this->guarded($request, function (User $actor, string $requestId) use ($request, $version): JsonResponse {
            if (! ctype_digit($version)) {
                throw new MapNotFoundException('Not a version.');
            }
            $expected = $this->menuVersion($request);
            if ($expected instanceof JsonResponse) {
                return $expected;
            }
            $this->admin->restoreMenu($actor, (int) $version, $expected, $requestId);

            return $this->menuResponse($requestId, Response::HTTP_OK);
        });
    }

    public function switchFunction(Request $request, string $key): JsonResponse
    {
        return $this->guarded($request, function (User $actor, string $requestId) use ($request, $key): JsonResponse {
            $state = $this->admin->functionState($key) ?? throw new MapNotFoundException('No such function.');
            $enabled = $request->json()->all()['enabled'] ?? null;
            if (! is_bool($enabled)) {
                throw new InvalidMapException(['enabled' => 'Must be true or false.']);
            }
            $expected = $this->ifMatch($request);
            if ($expected instanceof JsonResponse) {
                return $expected;
            }
            if ($expected !== $this->functionEtag($key, $state)) {
                return $this->fail($requestId, 'version_conflict', 'The resource version does not match the If-Match header.', Response::HTTP_PRECONDITION_FAILED);
            }
            $this->admin->setFunction($actor, $key, $enabled, $state, $requestId);

            return $this->ok($requestId, 'function', ['key' => $key, 'enabled' => $enabled], $this->functionEtag($key, $enabled));
        });
    }

    /**
     * Authorizes the caller as an administrator, then maps the domain exceptions to error answers.
     *
     * @param  Closure(User, string): JsonResponse  $action
     */
    private function guarded(Request $request, Closure $action): JsonResponse
    {
        $requestId = $this->requestId($request);
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->isPaneAdministrator()) {
            return $this->fail($requestId, 'permission_denied', 'Only administrators can edit maps.', Response::HTTP_FORBIDDEN);
        }

        try {
            return $action($actor, $requestId);
        } catch (MapNotFoundException) {
            return $this->fail($requestId, 'resource_not_found', 'The requested resource was not found.', Response::HTTP_NOT_FOUND);
        } catch (SystemMapProtectedException $exception) {
            return $this->fail($requestId, 'system_map_protected', $exception->getMessage(), Response::HTTP_CONFLICT);
        } catch (MapVersionConflictException) {
            return $this->fail($requestId, 'version_conflict', 'The resource version does not match the If-Match header.', Response::HTTP_PRECONDITION_FAILED);
        } catch (InvalidMapException $exception) {
            return $this->fail($requestId, 'validation_failed', 'The map is not valid.', Response::HTTP_UNPROCESSABLE_ENTITY, $exception->errors);
        }
    }

    /** @return array<string, mixed>|null */
    private function currentTable(string $source, string $table): ?array
    {
        try {
            return $this->reader->table($source, $table);
        } catch (MapNotFoundException) {
            return null;
        }
    }

    /** @param  array<string, mixed>  $current */
    private function tableVersion(Request $request, array $current): int|JsonResponse
    {
        $expected = $this->ifMatch($request);
        if ($expected instanceof JsonResponse) {
            return $expected;
        }
        if ($expected !== MapReader::etag('table_map', $current['version'], $current)) {
            return $this->fail($this->requestId($request), 'version_conflict', 'The resource version does not match the If-Match header.', Response::HTTP_PRECONDITION_FAILED);
        }

        return $current['version'];
    }

    private function menuVersion(Request $request): int|JsonResponse
    {
        $expected = $this->ifMatch($request);
        if ($expected instanceof JsonResponse) {
            return $expected;
        }
        $version = $this->admin->menu()['version'];
        if ($expected !== $this->menuEtag($version)) {
            return $this->fail($this->requestId($request), 'version_conflict', 'The resource version does not match the If-Match header.', Response::HTTP_PRECONDITION_FAILED);
        }

        return $version;
    }

    /** Creating a map: the caller says it expects the table not to be mapped yet. */
    private function ifNoneMatchStar(Request $request): ?JsonResponse
    {
        if ($request->header('If-Match') !== null) {
            return $this->fail($this->requestId($request), 'version_conflict', 'This table is not mapped yet: send If-None-Match: * to create its map.', Response::HTTP_PRECONDITION_FAILED);
        }
        if ($request->header('If-None-Match') === null) {
            return $this->fail($this->requestId($request), 'precondition_required', 'The If-None-Match: * header is required to create a map.', Response::HTTP_PRECONDITION_REQUIRED);
        }
        if ($request->header('If-None-Match') !== '*') {
            return $this->fail($this->requestId($request), 'invalid_request', 'The If-None-Match header must be *.', Response::HTTP_BAD_REQUEST);
        }

        return null;
    }

    private function ifMatch(Request $request): string|JsonResponse
    {
        $ifMatch = $request->header('If-Match');
        if ($ifMatch === null || $ifMatch === '') {
            return $this->fail($this->requestId($request), 'precondition_required', 'The If-Match header is required for this operation.', Response::HTTP_PRECONDITION_REQUIRED);
        }
        if (! preg_match('/\A"[A-Za-z0-9._~-]{1,128}"\z/', $ifMatch)) {
            return $this->fail($this->requestId($request), 'invalid_request', 'The If-Match header must be a quoted strong ETag.', Response::HTTP_BAD_REQUEST);
        }

        return $ifMatch;
    }

    private function tableResponse(string $requestId, string $source, string $table, int $status): JsonResponse
    {
        $attributes = $this->reader->table($source, $table);

        return $this->ok($requestId, 'table_map', $attributes, MapReader::etag('table_map', $attributes['version'], $attributes), $status);
    }

    private function menuResponse(string $requestId, int $status): JsonResponse
    {
        $menu = $this->admin->menu();

        return $this->ok($requestId, 'menu', $menu, $this->menuEtag($menu['version']), $status);
    }

    private function menuEtag(int $version): string
    {
        return '"menu-'.$version.'"';
    }

    private function functionEtag(string $key, bool $enabled): string
    {
        return '"'.$key.($enabled ? '-on' : '-off').'"';
    }

    /** @param  Collection<int, MapVersion>  $versions */
    private function history(string $requestId, Collection $versions): JsonResponse
    {
        return response()->json([
            'data' => $versions->map(fn (MapVersion $version): array => [
                'id' => (string) $version->version,
                'type' => 'map_version',
                'attributes' => [
                    'version' => $version->version,
                    'author' => $version->author,
                    'restored_from' => $version->restored_from,
                    'diff' => $version->diff,
                    'created_at' => $version->created_at?->toJSON(),
                ],
            ])->values(),
            'meta' => ['request_id' => $requestId],
        ])->header('X-Request-Id', $requestId)->header('Cache-Control', 'no-store');
    }

    /** @param  array<string, mixed>  $attributes */
    private function ok(string $requestId, string $type, array $attributes, string $etag, int $status = Response::HTTP_OK): JsonResponse
    {
        return response()->json([
            'data' => ['type' => $type, 'attributes' => $attributes],
            'meta' => ['request_id' => $requestId],
        ], $status)->header('X-Request-Id', $requestId)->header('ETag', $etag)->header('Cache-Control', 'no-store');
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
