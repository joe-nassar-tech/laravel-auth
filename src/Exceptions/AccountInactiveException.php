<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Exceptions;

class AccountInactiveException extends AuthException
{
    public function __construct(
        string $message = 'This account has been deactivated.',
        ?string $errorKey = null,
        array $replacements = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $errorKey, $replacements, $previous);
    }

    protected function defaultErrorKey(): ?string
    {
        return 'account_inactive';
    }
}
