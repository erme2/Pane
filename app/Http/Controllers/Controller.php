<?php

namespace App\Http\Controllers;

use App\Exceptions\SystemException;
use App\Helpers\ResponseHelper;
use App\Helpers\StoryHelper;
use App\Mappers\AbstractMapper;
use App\Models\User;
use App\Services\AppMaps;
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

        return $this->success($story->run($subject, $key));
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
        if ($scope === null && in_array($request->method(), [Request::METHOD_POST, Request::METHOD_PUT, Request::METHOD_DELETE], true)) {
            throw new SystemException("Forbidden CRUD mutation ($subject)", Response::HTTP_FORBIDDEN);
        }

        return $scope;
    }
}
