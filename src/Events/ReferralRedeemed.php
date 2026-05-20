<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Joe404\LaravelAuth\Models\Referral;

/**
 * Fired AFTER the reward handler ran successfully and the referral
 * row's redeemed_at was set. By this point status is "valid" and the
 * referrer's reward has been applied by the developer's handler.
 *
 * Listen here for post-reward side-effects (analytics, follow-up email,
 * gamification badges, etc.) that should not retry if the reward handler
 * itself fails.
 */
class ReferralRedeemed
{
    use Dispatchable;

    public function __construct(
        public readonly Referral $referral,
    ) {}
}
