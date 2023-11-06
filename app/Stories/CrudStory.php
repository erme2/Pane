<?php

namespace App\Stories;

use App\Exceptions\SystemException;
use Illuminate\Http\Request;

/**
 * Class CrudStory
 * this is the base class for all CRUD stories, based on the request method it will
 * execute the actions to create, read, update or delete a record
 *
 * @package App\Stories
 */
class CrudStory extends AbstractStory
{

    /**
     * defines the actions to be executed in the story
     *
     * @throws SystemException
     */
    public function __construct(Request $request)
    {
        parent::__construct($request);

        switch ($this->plot->requestData['method']) {
            // create
            case Request::METHOD_POST:
                $this->plot->options['is_new_record'] = true;
                $this->actions = [
                    'validate',
                    'save',
                ];
                break;
            // update
            case Request::METHOD_PUT:
                $this->actions = [
                    'validate',
                    'save',
                ];
                break;
            // read
            case Request::METHOD_GET:
                $this->actions = [
                    'read',
                ];
                break;
            // delete
            case Request::METHOD_DELETE:
                $this->actions = [
                    'delete',
                ];
                break;
            default:
                throw new SystemException(
                    "Method not allowed (method: {$this->plot->requestData['method']} object: ".
                    get_class($this).")", 405);
        }
    }
}
