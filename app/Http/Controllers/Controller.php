<?php

namespace App\Http\Controllers;

use App\Exceptions\SystemException;
use App\Exceptions\ValidationException;
use App\Helpers\ResponseHelper;
use App\Helpers\StoryHelper;
use App\Mappers\AbstractMapper;
use App\Models\User;
use App\Services\AppMaps;
use App\Stories\AbstractStory;
use App\Support\Maps\MapQueryRules;
use App\Support\Maps\RowScope;
use App\Support\ReleaseMetadata;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class Controller extends BaseController
{
    use ResponseHelper, StoryHelper;

    private const PROTECTED_CRUD_SUBJECTS = [
        AbstractMapper::TABLES['tables'],
        AbstractMapper::TABLES['fields'],
        AbstractMapper::TABLES['field_types'],
        AbstractMapper::TABLES['field_validations'],
        AbstractMapper::TABLES['validation_types'],
        AbstractMapper::TABLES['users'],
        AbstractMapper::TABLES['user_types'],
        AbstractMapper::TABLES['audit_events'],
    ];

    /**
     * Redirect public root traffic away from the operational API.
     */
    public function index(): RedirectResponse
    {
        return redirect('/docs');
    }

    public function docs(): Response
    {
        return response($this->docsHtml(), Response::HTTP_OK)
            ->header('Content-Type', 'text/html; charset=UTF-8');
    }

    public function openApi(): JsonResponse
    {
        $contract = json_decode(
            File::get(base_path('contracts/pane-v1.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        return response()->json($contract);
    }

    public function status(Request $request, ReleaseMetadata $releaseMetadata): JsonResponse
    {
        $authResponse = $this->statusAuthResponse($request);
        if ($authResponse instanceof JsonResponse) {
            return $this->uncached($authResponse);
        }

        return $this->uncached(response()->json([
            'status' => 'OK',
            'data' => [
                'message' => 'Pane RestAPI is available',
                'release' => $releaseMetadata->current(),
            ],
        ]));
    }

    private function statusAuthResponse(Request $request): ?JsonResponse
    {
        $username = config('app.status_username', 'pane');
        $password = config('app.status_password');

        if (! is_string($password) || $password === '') {
            return response()->json([
                'message' => 'Pane status password is not configured.',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        if (
            ! is_string($username)
            || ! hash_equals($username, (string) $request->getUser())
            || ! hash_equals($password, (string) $request->getPassword())
        ) {
            return response()
                ->json(['message' => 'Authentication required.'], Response::HTTP_UNAUTHORIZED)
                ->header('WWW-Authenticate', 'Basic realm="Pane Status"');
        }

        return null;
    }

    private function uncached(JsonResponse $response): JsonResponse
    {
        return $response
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, private')
            ->header('Pragma', 'no-cache');
    }

    private function docsHtml(): string
    {
        return <<<'HTML'
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pane REST API Documentation</title>
    <link rel="icon" href="/icon.svg" type="image/svg+xml">
    <link rel="icon" href="/favicon.ico" sizes="32x32">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    <link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist@5/swagger-ui.css">
    <style>
        body { margin: 0; background: #f7f8fb; }
        .topbar {
            align-items: center;
            background: #111827;
            color: #fff;
            display: flex;
            font: 14px system-ui, sans-serif;
            gap: 16px;
            justify-content: space-between;
            padding: 12px 20px;
        }
        .topbar a { color: #bfdbfe; text-decoration: none; }
        .topbar strong { font-size: 16px; }
    </style>
</head>
<body>
    <header class="topbar">
        <strong>Pane REST API</strong>
        <a href="/api/v1/release">Release metadata</a>
    </header>
    <div id="swagger-ui"></div>
    <script src="https://unpkg.com/swagger-ui-dist@5/swagger-ui-bundle.js"></script>
    <script>
        window.addEventListener('load', function () {
            SwaggerUIBundle({
                url: '/docs/openapi.json',
                dom_id: '#swagger-ui',
                deepLinking: true,
                displayRequestDuration: true
            });
        });
    </script>
</body>
</html>
HTML;
    }

    /**
     * just runs the requested story
     *
     * @throws SystemException
     */
    public function runStory(Request $request, string $story, string $subject, $key = null): Response
    {
        return $this->success($this->prepareStory($request, $story, $subject)->run($subject, $key));
    }

    /**
     * Runs a named query of a table map (Pane#225): the query's filter, sort and join with the caller's
     * typed parameters, through the same list read and the same access rules as `GET /crud/<table>`.
     *
     * @throws SystemException
     * @throws ValidationException
     */
    public function runNamedQuery(Request $request, string $story, string $subject, string $name): Response
    {
        if (strtolower($story) !== 'crud') {
            throw new SystemException("Unknown story ($story)", Response::HTTP_NOT_FOUND);
        }

        // A table without a map has no named queries, so 404 is true for everyone and tells no one
        // anything; it keeps its 404 ahead of the access rule (Pane#232).
        $map = app(AppMaps::class)->table(Str::snake($subject));
        if ($map === null) {
            throw new SystemException("Unknown named query ($subject/$name)", Response::HTTP_NOT_FOUND);
        }
        $prepared = $this->prepareStory($request, $story, $subject);

        $queries = $map->queries ?? [];
        $query = collect($queries)->firstWhere('name', $name);
        if ($query === null) {
            throw new SystemException("Unknown named query ($subject/$name)", Response::HTTP_NOT_FOUND);
        }
        $prepared->plot->requestData['data'] = MapQueryRules::bind($query, $request->all());

        return $this->success($prepared->run($subject));
    }

    /** The story with the caller's access rule on its subject and joined tables applied. */
    private function prepareStory(Request $request, string $story, string $subject): AbstractStory
    {
        $scope = $this->authorizeStory($request, $story, $subject);
        $storyName = $story;

        $story = $this->loadStory($request, $story);
        if ($scope !== null) {
            $story->plot->options['member_scope'] = $scope;
        }
        if (strtolower($storyName) === 'crud') {
            // Joined tables are read through their own access rule, resolved like the request's subject.
            $story->plot->options['join_scope'] = fn (string $joined) => $this->authorizeStory($request, $storyName, $joined);
        }

        return $story;
    }

    /**
     * Administrators reach every subject, except the rows of an owner-rule table
     * that are not theirs (Pane#218). Anyone else is refused on system
     * subjects, and on writes unless the subject's map has a members rule
     * (Pane#185): then the returned scope limits them to the rows they belong to.
     *
     * @throws SystemException
     */
    private function authorizeStory(Request $request, string $story, string $subject): ?RowScope
    {
        if (strtolower($story) !== 'crud') {
            return null;
        }

        // The mapper resolves tables with Str::snake(), so authorise on that same name.
        $subject = Str::snake($subject);

        $user = $request->user();
        $isAdministrator = (int) $user?->user_type_id === User::PANE_ADMINISTRATOR_USER_TYPE_ID;

        if (in_array($subject, self::PROTECTED_CRUD_SUBJECTS, true) && ! $isAdministrator) {
            throw new SystemException("Forbidden CRUD subject ($subject)", Response::HTTP_FORBIDDEN);
        }

        // The reports rule (Pane#245): everyone files, only administrators read, update the status and nothing else.
        $reports = $user instanceof User ? app(AppMaps::class)->reportScope($subject, $user, $isAdministrator) : null;
        if ($reports !== null) {
            if (! $isAdministrator && $request->method() !== Request::METHOD_POST) {
                throw $reports->forbidden();
            }

            return $reports;
        }

        // The owner rule holds for administrators too (Pane#218).
        $owner = $user instanceof User ? app(AppMaps::class)->ownerScope($subject, $user) : null;
        if ($owner !== null) {
            return $owner;
        }

        if ($isAdministrator) {
            // An administrator sees every row, but a new list still gets its creator from Pane (Pane#227).
            if ($user instanceof User && filled($user->uuid) && in_array($request->method(), [Request::METHOD_POST, Request::METHOD_PUT], true)) {
                $creatorScope = app(AppMaps::class)->memberScope($subject, $user);

                return $creatorScope?->creatorField !== null ? $creatorScope->asUnrestricted() : null;
            }

            return null;
        }

        $scope = $user instanceof User ? app(AppMaps::class)->memberScope($subject, $user) : null;
        if ($scope === null) {
            $isWrite = in_array($request->method(), [Request::METHOD_POST, Request::METHOD_PUT, Request::METHOD_DELETE], true);
            if ($isWrite) {
                throw new SystemException("Forbidden CRUD mutation ($subject)", Response::HTTP_FORBIDDEN);
            }
            // A table with no access rule is refused, reads included (Pane#232).
            if (! app(AppMaps::class)->hasReadRule($subject)) {
                throw new SystemException("No access rule for CRUD subject ($subject)", Response::HTTP_FORBIDDEN);
            }
        }

        return $scope;
    }
}
