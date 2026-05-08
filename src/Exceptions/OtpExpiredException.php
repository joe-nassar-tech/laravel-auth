<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Exceptions;

class OtpExpiredException extends AuthException
{
    public function __construct(string $message = 'The OTP code has expired.', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
