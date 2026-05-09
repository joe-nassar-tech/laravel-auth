<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Joe404\LaravelAuth\Models\AuthRefreshToken;

class CleanExpiredRefreshTokens implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function handle(): void
    {
        // Delete refresh tokens that no longer have any forensic value:
        //  - Expired beyond the keep-window (default 7 days) — past this point
        //    even reuse-detection on a leaked-but-rotated token is moot.
        //  - Consumed more than 7 days ago — kept for that window so a recent
        //    replay still trips reuse detection.
        //  - Revoked more than 7 days ago.
        $cutoff = now()->subDays(7);

        AuthRefreshToken::query()
            ->where(function ($q) use ($cutoff): void {
                $q->whereNotNull('expires_at')->where('expires_at', '<', $cutoff);
            })
            ->orWhere(function ($q) use ($cutoff): void {
                $q->whereNotNull('consumed_at')->where('consumed_at', '<', $cutoff);
            })
            ->orWhere(function ($q) use ($cutoff): void {
                $q->whereNotNull('revoked_at')->where('revoked_at', '<', $cutoff);
            })
            ->delete();
    }
}
