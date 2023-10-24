<?php

namespace App\Actions;

use App\Helpers\MapperHelper;
use App\Stories\StoryPlot;
use Illuminate\Http\Request;

class ValidateAction extends AbstractAction
{
    use MapperHelper;

    public function exec(string $subject, StoryPlot $plot, mixed $key = null): StoryPlot
    {
        $mapper = $this->getMapper($subject);
        switch (\request()->method()) {
            case Request::METHOD_POST: // create
                $rules = $mapper->getValidation('rules', false);
                $messages = $mapper->getValidation('messages', false);
                break;
            case Request::METHOD_PUT: // update
            default:
                $rules = $mapper->getValidation();
                $messages = $mapper->getValidation('messages');
        }

//        $errors = \Validator::make(
//                \request()->all(),
//                $rules,
//                $messages
//            )
//            ->errors()
//        ;
//print_R($errors);
print_R($rules);
print_r($messages);
die("@ $subject");

    }
}
