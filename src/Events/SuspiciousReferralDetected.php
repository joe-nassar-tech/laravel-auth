<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Joe404\LaravelAuth\Models\Referral;

/**
 * Fired when a referral is created with status "suspicious" or "blocked".
 *
 * Use this to alert admins, post to Slack, write to a fraud dashboard,
 * etc. The reward is NOT fired and registration still succeeds — only
 * the referral relationship is marked questionable.
 */
class SuspiciousReferralDetected
{
    use Dispatchable;

    public function __construct(
        public readonly Referral $referral,
        public readonly string $reason,
    ) {}
}
