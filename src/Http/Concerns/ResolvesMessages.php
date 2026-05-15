<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Http\Concerns;

use Throwable;
use Joe404\LaravelAuth\Exceptions\AuthException;

/**
 * Resolves user-facing strings the package returns in JSON responses.
 *
 * Resolution order for success messages (`msg()`):
 *   1. config('auth_system.messages.<key>')      — host static override
 *   2. trans('auth_system::messages.<key>')      — host translation file
 *   3. The hardcoded $default passed by the controller
 *
 * Resolution order for error messages (`err()`):
 *   1. config('auth_system.errors.<errorKey>')   — host static override
 *   2. trans('auth_system::errors.<errorKey>')   — host translation file
 *   3. $e->getMessage()                          — built-in English fallback
 *
 * The active locale is whatever Laravel reports via app()->getLocale() at
 * request time, so any locale middleware your app already uses just works.
 */
trait ResolvesMessages
{
    /**
     * Resolve a controller success-response message.
     */
    protected function msg(string $key, string $default): string
    {
        $override = config("auth_system.messages.{$key}");

        if (is_string($override) && $override !== '') {
            return $override;
        }

        $translation = trans("auth_system::messages.{$key}");

        if (is_string($translation) && $translation !== "auth_system::messages.{$key}" && $translation !== '') {
            return $translation;
        }

        return $default;
    }

    /**
     * Resolve the user-facing message for a thrown exception.
     *
     * Accepts an explicit $errorKey for non-AuthException catches (e.g.
     * \DomainException, AuthorizationException). When omitted, falls back
     * to the exception's own errorKey() (AuthException family).
     *
     * @param array<string, scalar|null> $replacements
     */
    protected function err(Throwable $e, ?string $errorKey = null, array $replacements = []): string
    {
        if ($errorKey === null && $e instanceof AuthException) {
            $errorKey    = $e->errorKey();
            $replacements = $replacements + $e->errorReplacements();
        }

        if ($errorKey !== null) {
            $override = config("auth_system.errors.{$errorKey}");

            if (is_string($override) && $override !== '') {
                return $this->interpolate($override, $replacements);
            }

            $translation = trans("auth_system::errors.{$errorKey}", $replacements);

            if (is_string($translation) && $translation !== "auth_system::errors.{$errorKey}" && $translation !== '') {
                return $translation;
            }
        }

        return $e->getMessage() !== '' ? $e->getMessage() : ($errorKey ?? 'An error occurred.');
    }

    /**
     * Resolve a raw error key when there is no exception in scope (e.g. for
     * the renderable() handler that catches Illuminate's auth exception).
     *
     * @param array<string, scalar|null> $replacements
     */
    protected function errKey(string $errorKey, string $default, array $replacements = []): string
    {
        $override = config("auth_system.errors.{$errorKey}");

        if (is_string($override) && $override !== '') {
            return $this->interpolate($override, $replacements);
        }

        $translation = trans("auth_system::errors.{$errorKey}", $replacements);

        if (is_string($translation) && $translation !== "auth_system::errors.{$errorKey}" && $translation !== '') {
            return $translation;
        }

        return $this->interpolate($default, $replacements);
    }

    /**
     * @param array<string, scalar|null> $replacements
     */
    private function interpolate(string $message, array $replacements): string
    {
        foreach ($replacements as $key => $value) {
            $message = str_replace(':' . $key, (string) $value, $message);
        }

        return $message;
    }
}
