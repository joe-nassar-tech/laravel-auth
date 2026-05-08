<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Exceptions;

class AccountInactiveException extends AuthException
{
    public function __construct(string $message = 'This account has been deactivated.', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
