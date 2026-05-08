<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Joe404\LaravelAuth\Models\AuthOtpCode;

class CleanExpiredOtpRecords implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function handle(): void
    {
        AuthOtpCode::where('expires_at', '<', now())->whereNull('used_at')->delete();
    }
}
