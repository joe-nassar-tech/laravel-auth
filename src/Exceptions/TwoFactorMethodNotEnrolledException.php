<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Exceptions;

class TwoFactorMethodNotEnrolledException extends AuthException
{
    protected function defaultErrorKey(): ?string
    {
        return 'two_factor_method_not_enrolled';
    }
}
