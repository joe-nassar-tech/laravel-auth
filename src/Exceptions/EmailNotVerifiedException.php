<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Exceptions;

class EmailNotVerifiedException extends AuthException
{
    public function __construct(string $message = 'Email address is not verified.', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
