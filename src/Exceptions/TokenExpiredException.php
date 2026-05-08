<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Exceptions;

class TokenExpiredException extends AuthException
{
    public function __construct(string $message = 'The API token has expired.', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
