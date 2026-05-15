<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Exceptions;

class OtpInvalidException extends AuthException
{
    public function __construct(
        string $message = 'The OTP code is invalid.',
        ?string $errorKey = null,
        array $replacements = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $errorKey, $replacements, $previous);
    }

    protected function defaultErrorKey(): ?string
    {
        return 'otp_invalid';
    }
}
