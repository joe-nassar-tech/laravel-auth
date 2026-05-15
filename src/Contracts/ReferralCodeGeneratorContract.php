<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Contracts;

interface ReferralCodeGeneratorContract
{
    /**
     * Generate a unique referral code.
     *
     * Implementations must guarantee uniqueness against the configured
     * users column (auth_system.referral_code.column).
     */
    public function generate(): string;
}
