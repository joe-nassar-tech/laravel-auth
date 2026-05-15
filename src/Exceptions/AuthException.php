<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Exceptions;

use RuntimeException;
use Throwable;

class AuthException extends RuntimeException
{
    /**
     * Translation key under "auth_system::errors.*" used to localize the
     * message at the controller boundary. May be null for legacy throws,
     * in which case the raw message string is shown verbatim.
     *
     * @var string|null
     */
    protected ?string $errorKey;

    /**
     * Placeholders interpolated into the translated message, e.g.
     *   throw new AuthException('Locked.', 'account_locked', ['seconds' => 30])
     *
     * @var array<string, scalar|null>
     */
    protected array $errorReplacements;

    /**
     * @param array<string, scalar|null> $replacements
     */
    public function __construct(
        string $message = '',
        ?string $errorKey = null,
        array $replacements = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);

        $this->errorKey          = $errorKey ?? $this->defaultErrorKey();
        $this->errorReplacements = $replacements;
    }

    public function errorKey(): ?string
    {
        return $this->errorKey;
    }

    /**
     * @return array<string, scalar|null>
     */
    public function errorReplacements(): array
    {
        return $this->errorReplacements;
    }

    /**
     * Subclasses override this to provide a default translation key when
     * none is supplied to the constructor. Returning null falls back to
     * the raw message string.
     */
    protected function defaultErrorKey(): ?string
    {
        return null;
    }
}
