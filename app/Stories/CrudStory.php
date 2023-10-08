<?php

namespace App\Stories;

use App\Exceptions\PaneException;
use Illuminate\Http\Request;

class CrudStory extends AbstractStory
{

    /**
     * defines the actions to be executed in the story
     *
     * @throws PaneException
     */
    public function __construct()
    {
        switch (\request()->method()) {
            case Request::METHOD_POST: // create
            case Request::METHOD_PUT: // update
                $this->actions = [
                    'validate',
                    'save',
                ];
                break;
            case Request::METHOD_GET: // read
                $this->actions = [
                    'read',
                ];
                break;
            case Request::METHOD_DELETE: // delete
                $this->actions = [
                    'delete',
                ];
                break;
            default:
                throw new PaneException("Method not allowed (method: ".\request()->method().
                    " object: ".get_class($this).")", 405);
        }
    }
}
