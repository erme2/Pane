<?php

namespace App\Support\ManagedDatabase;

use RuntimeException;

final class EgressDeniedException extends RuntimeException
{
    public function __construct(public readonly EgressDecision $decision)
    {
        parent::__construct($decision->message());
    }
}
