<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Exceptions;

class TwoFactorChallengeInvalidException extends AuthException
{
    protected function defaultErrorKey(): ?string
    {
        return 'two_factor_challenge_invalid';
    }
}
