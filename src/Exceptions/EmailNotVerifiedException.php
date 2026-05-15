<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Exceptions;

class EmailNotVerifiedException extends AuthException
{
    public function __construct(
        string $message = 'Email address is not verified.',
        ?string $errorKey = null,
        array $replacements = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $errorKey, $replacements, $previous);
    }

    protected function defaultErrorKey(): ?string
    {
        return 'email_not_verified';
    }
}
