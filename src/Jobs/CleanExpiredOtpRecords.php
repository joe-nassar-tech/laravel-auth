<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Joe404\LaravelAuth\Models\AuthOtpCode;
use Joe404\LaravelAuth\Models\AuthPhoneOtpCode;
use Joe404\LaravelAuth\Models\AuthTwoFactorChallenge;

class CleanExpiredOtpRecords implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function handle(): void
    {
        AuthOtpCode::where('expires_at', '<', now())->whereNull('used_at')->delete();

        // v2.6 — drop expired phone OTPs and 2FA challenges (consumed rows are
        // kept briefly for audit, then purged after a 24h grace).
        AuthPhoneOtpCode::where('expires_at', '<', now())
            ->whereNull('consumed_at')
            ->delete();

        AuthPhoneOtpCode::where('consumed_at', '<', now()->subDay())->delete();

        AuthTwoFactorChallenge::where('expires_at', '<', now())
            ->whereNull('consumed_at')
            ->delete();

        AuthTwoFactorChallenge::where('consumed_at', '<', now()->subDay())->delete();
    }
}
