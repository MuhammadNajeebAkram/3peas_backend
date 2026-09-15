<?php

namespace App\Exceptions;

use RuntimeException;

class AiGenerationException extends RuntimeException
{
    public function __construct(string $message, public readonly int $requestId, public readonly int $httpStatus = 502)
    {
        parent::__construct($message);
    }
}
