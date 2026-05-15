<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Exceptions;

class TokenRevokedException extends AuthException
{
    public function __construct(
        string $message = 'The token has been revoked.',
        ?string $errorKey = null,
        array $replacements = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $errorKey, $replacements, $previous);
    }

    protected function defaultErrorKey(): ?string
    {
        return 'api_token_revoked';
    }
}
