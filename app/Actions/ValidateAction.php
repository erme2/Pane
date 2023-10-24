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
                $rules = $mapper->getValidationRules(false);
                $messages = $mapper->getValidationMessages(false);
                break;
            case Request::METHOD_PUT: // update
            default:
                $rules = $mapper->getValidationRules();
                $messages = $mapper->getValidationMessages();
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
