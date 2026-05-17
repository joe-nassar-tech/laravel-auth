<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Schema;
use Joe404\LaravelAuth\Services\AccountStatusService;
use Joe404\LaravelAuth\Support\AccountStatus;

/**
 * Sweeps users whose `status_expires_at` has elapsed and flips them back to
 * `active` via AccountStatusService::changeStatus(), so the normal
 * AccountStatusChanged event + (optional) notification fire exactly once.
 *
 * Note that the lazy path inside AccountStatusService::current() may already
 * have reverted the user — the job's check is idempotent, so duplicates do
 * nothing.
 */
class RevertExpiredAccountStatuses implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function handle(AccountStatusService $service): void
    {
        if (! (bool) config('auth_system.account.status.auto_unban.enabled', true)) {
            return;
        }

        /** @var class-string<\Illuminate\Foundation\Auth\User> $userModel */
        $userModel = config('auth.providers.users.model', \App\Models\User::class);

        if (! Schema::hasColumn((new $userModel)->getTable(), 'status_expires_at')) {
            return;
        }

        $column = AccountStatus::column();

        $userModel::query()
            ->whereNotNull('status_expires_at')
            ->where('status_expires_at', '<=', now())
            ->where($column, '!=', AccountStatus::ACTIVE)
            ->orderBy('status_expires_at')
            ->chunkById(200, function ($users) use ($service): void {
                foreach ($users as $user) {
                    try {
                        $service->revertIfExpired($user, 'auto_unban_sweep');
                    } catch (\Throwable $e) {
                        report($e);
                    }
                }
            });
    }
}
