<?php

namespace App\Http\Controllers;

use App\Exceptions\SystemException;
use App\Helpers\ResponseHelper;
use App\Helpers\StoryHelper;
use App\Mappers\AbstractMapper;
use App\Models\User;
use App\Stories\StoryPlot;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller as BaseController;

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
        AbstractMapper::TABLES['organizations'],
        AbstractMapper::TABLES['organization_memberships'],
        AbstractMapper::TABLES['audit_events'],
    ];

    /**
     * just returns a welcome message
     *
     * @throws SystemException
     */
    public function index(): Response
    {
        $response = new StoryPlot;
        $response->setStatus(Response::HTTP_OK);
        $response->data = [
            'message' => 'Welcome to Pane RestAPI',
        ];

        return $this->success($response);
    }

    /**
     * just runs the requested story
     *
     * @throws SystemException
     */
    public function runStory(Request $request, string $story, string $subject, $key = null): Response
    {
        $this->authorizeStory($request, $story, $subject);

        $story = $this->loadStory($request, $story);

        return $this->success($story->run($subject, $key));
    }

    /**
     * @throws SystemException
     */
    private function authorizeStory(Request $request, string $story, string $subject): void
    {
        if (strtolower($story) !== 'crud') {
            return;
        }

        $user = $request->user();
        $isAdministrator = (int) $user?->user_type_id === User::PANE_ADMINISTRATOR_USER_TYPE_ID;

        if (in_array($subject, self::PROTECTED_CRUD_SUBJECTS, true) && ! $isAdministrator) {
            throw new SystemException("Forbidden CRUD subject ($subject)", Response::HTTP_FORBIDDEN);
        }

        if (in_array($request->method(), [Request::METHOD_POST, Request::METHOD_PUT, Request::METHOD_DELETE], true) && ! $isAdministrator) {
            throw new SystemException("Forbidden CRUD mutation ($subject)", Response::HTTP_FORBIDDEN);
        }
    }
}
