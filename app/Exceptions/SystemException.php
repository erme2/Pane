<?php

namespace App\Exceptions;

use Throwable;

class SystemException extends \Exception
{
    public function __construct(string $message = "", int $code = 0, ?Throwable $previous = null)
    {
        $message = "System Exception: $message";
        parent::__construct($message, $code, $previous);
    }
}
