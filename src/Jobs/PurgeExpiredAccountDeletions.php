<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Joe404\LaravelAuth\Models\DeletedAccount;
use Joe404\LaravelAuth\Services\AccountDeletionService;

class PurgeExpiredAccountDeletions implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function handle(AccountDeletionService $service): void
    {
        DeletedAccount::query()
            ->whereNull('purged_at')
            ->where('scheduled_purge_at', '<=', now())
            ->orderBy('scheduled_purge_at')
            ->chunkById(100, function ($entries) use ($service): void {
                foreach ($entries as $entry) {
                    try {
                        $service->purge($entry);
                    } catch (\Throwable $e) {
                        // Log and continue — a single bad row must not block the batch.
                        report($e);
                    }
                }
            });
    }
}
