<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Joe404\LaravelAuth\Models\Referral;

/**
 * Fired the moment a referral record is persisted.
 *
 * Always fires, regardless of final status — listen for this if you want
 * to log every referral attempt. To act ONLY on valid referrals, use
 * ReferralRedeemed instead. To act ONLY on suspicious/blocked ones, use
 * SuspiciousReferralDetected.
 */
class ReferralCreated
{
    use Dispatchable;

    public function __construct(
        public readonly Referral $referral,
    ) {}
}
