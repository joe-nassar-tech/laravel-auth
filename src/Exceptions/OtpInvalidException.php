<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Exceptions;

class OtpInvalidException extends AuthException
{
    public function __construct(string $message = 'The OTP code is invalid.', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
