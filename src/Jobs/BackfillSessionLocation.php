<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Joe404\LaravelAuth\Models\AuthSessionExtended;
use Joe404\LaravelAuth\Services\DeviceService;

/**
 * Resolves country/city for a session row asynchronously so the auth
 * request itself never blocks on a third-party GeoIP service. The
 * session is created with country/city = null on login; this job
 * fills them in once the lookup returns (best-effort — the session
 * is fully usable without it).
 */
class BackfillSessionLocation implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public function __construct(
        public readonly int $sessionId,
        public readonly string $ip,
    ) {
        $this->onQueue((string) config('auth_system.device.location_queue', 'default'));
    }

    public function handle(DeviceService $deviceService): void
    {
        $location = $deviceService->resolveLocation($this->ip);

        if (($location['country'] ?? null) === null && ($location['city'] ?? null) === null) {
            return;
        }

        AuthSessionExtended::where('id', $this->sessionId)->update([
            'country' => $location['country'] ?? null,
            'city'    => $location['city'] ?? null,
        ]);
    }
}
