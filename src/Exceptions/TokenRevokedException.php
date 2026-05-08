<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Exceptions;

class TokenRevokedException extends AuthException
{
    public function __construct(string $message = 'The token has been revoked.', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
