<?php

namespace App\Exceptions;

use Throwable;

class SystemException extends \Exception
{
    const ERROR_MESSAGE_PREFIX = 'System Exception: ';

    public function __construct(string $message = "", int $code = 0, ?Throwable $previous = null)
    {
        $message = self::ERROR_MESSAGE_PREFIX."$message";
        parent::__construct($message, $code, $previous);
    }
}
