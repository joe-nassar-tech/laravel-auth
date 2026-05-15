<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Services;

use Illuminate\Support\Facades\Cache;
use Joe404\LaravelAuth\Exceptions\AuthException;

class LockoutService
{
    private function countKey(string $email): string
    {
        return 'auth:lockout_count:' . sha1(strtolower($email));
    }

    private function lockKey(string $email): string
    {
        return 'auth:locked:' . sha1(strtolower($email));
    }

    public function isLockedOut(string $email): bool
    {
        return Cache::has($this->lockKey($email));
    }

    public function recordFailure(string $email): void
    {
        if (! (bool) config('auth_system.security.lockout.enabled', true)) {
            return;
        }

        $max   = (int) config('auth_system.security.lockout.max_attempts', 10);
        $decay = (int) config('auth_system.security.lockout.decay_minutes', 15);

        $countKey = $this->countKey($email);
        $count    = (int) Cache::get($countKey, 0) + 1;

        Cache::put($countKey, $count, now()->addMinutes($decay));

        if ($count >= $max) {
            Cache::put($this->lockKey($email), true, now()->addMinutes($decay));
            Cache::forget($countKey);
        }
    }

    public function throwIfLockedOut(string $email): void
    {
        if (! $this->isLockedOut($email)) {
            return;
        }

        $decay = (int) config('auth_system.security.lockout.decay_minutes', 15);

        throw new AuthException(
            "Account temporarily locked due to too many failed attempts. Try again in {$decay} minute(s).",
            'account_locked',
            ['seconds' => $decay * 60],
        );
    }

    public function clear(string $email): void
    {
        Cache::forget($this->countKey($email));
        Cache::forget($this->lockKey($email));
    }
}
