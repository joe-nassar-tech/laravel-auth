<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Contracts;

use Joe404\LaravelAuth\Models\Referral;

interface ReferralRewardHandlerContract
{
    /**
     * Apply the reward for a valid referral.
     *
     * Called by ReferralService exactly once per referral, the moment the
     * referral transitions to status="valid". The package guarantees:
     *
     *   - $referral->status === Referral::STATUS_VALID
     *   - $referral->redeemed_at is still null when this method runs
     *   - $referral->referrer and $referral->referred are loaded
     *
     * The handler is responsible for whatever the reward is in the host
     * app: crediting a wallet, granting a free subscription month,
     * generating a discount coupon, posting to an external API, etc.
     *
     * Failure handling: throw to abort the redemption. The package will
     * roll the referral back to status="pending" so the host can retry
     * (e.g. from a queue). Returning normally marks the referral
     * redeemed_at=now() and dispatches ReferralRedeemed.
     */
    public function handle(Referral $referral): void;
}
