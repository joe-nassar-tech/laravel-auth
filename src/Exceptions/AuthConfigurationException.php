<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Exceptions;

/**
 * Thrown when the package is asked to do something its config does not
 * support — e.g. generate a frontend magic link without a frontend URL
 * configured. These are programmer errors, not user errors, so they
 * should surface loudly during development rather than producing
 * silently-broken emails in production.
 */
class AuthConfigurationException extends AuthException
{
    public function __construct(
        string $message = 'Auth package is misconfigured.',
        ?string $errorKey = null,
        array $replacements = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $errorKey, $replacements, $previous);
    }

    protected function defaultErrorKey(): ?string
    {
        return 'auth_misconfigured';
    }
}
