<?php

namespace App\Exceptions;

use Throwable;

class PaneException extends \Exception
{
    public function __construct(string $message = "", int $code = 0, ?Throwable $previous = null)
    {
        $message = "System Exception: $message";
        parent::__construct($message, $code, $previous);
    }
}
