<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Services;

use Illuminate\Support\Facades\Cache;
use Joe404\LaravelAuth\Exceptions\AuthException;

class LockoutService
{
    /**
     * Build the lockout subject from the configured scope. The default
     * ('email') is byte-identical to the pre-v2.7 key, so behavior is unchanged
     * unless a host opts into an IP-aware scope.
     *
     *   email         → lock the address regardless of source IP. Simple, but
     *                   DoS-prone: anyone who knows the email can keep it locked.
     *   ip            → lock the source IP regardless of address.
     *   email_and_ip  → lock the (address, IP) pair, so an attacker only locks
     *                   their own IP for that email; the real owner, on a
     *                   different IP, is unaffected. Best anti-DoS option.
     */
    private function subject(string $email, ?string $ip): string
    {
        $email = strtolower($email);
        $scope = (string) config('auth_system.security.lockout.scope', 'email');

        return match ($scope) {
            'ip'           => 'ip:' . ($ip ?? 'unknown'),
            'email_and_ip' => 'eip:' . $email . '|' . ($ip ?? 'unknown'),
            default        => $email, // byte-identical to the pre-v2.7 key
        };
    }

    private function countKey(string $email, ?string $ip): string
    {
        return 'auth:lockout_count:' . sha1($this->subject($email, $ip));
    }

    private function lockKey(string $email, ?string $ip): string
    {
        return 'auth:locked:' . sha1($this->subject($email, $ip));
    }

    private function strikeKey(string $email, ?string $ip): string
    {
        return 'auth:lockout_strikes:' . sha1($this->subject($email, $ip));
    }

    public function isLockedOut(string $email, ?string $ip = null): bool
    {
        return Cache::has($this->lockKey($email, $ip));
    }

    public function recordFailure(string $email, ?string $ip = null): void
    {
        if (! (bool) config('auth_system.security.lockout.enabled', true)) {
            return;
        }

        $max   = max(1, (int) config('auth_system.security.lockout.max_attempts', 10));
        $decay = max(1, (int) config('auth_system.security.lockout.decay_minutes', 15));

        $countKey = $this->countKey($email, $ip);
        $count    = (int) Cache::get($countKey, 0) + 1;

        Cache::put($countKey, $count, now()->addMinutes($decay));

        if ($count >= $max) {
            $lockMinutes = $decay;

            // Exponential back-off (opt-in; defaults off): repeat offenders get
            // progressively longer locks — doubling per completed cycle, capped
            // — instead of a flat window.
            if ((bool) config('auth_system.security.lockout.backoff', false)) {
                $strikeKey   = $this->strikeKey($email, $ip);
                $strikes     = (int) Cache::get($strikeKey, 0);
                $lockMinutes = $decay * (2 ** min($strikes, 5));
                Cache::put($strikeKey, $strikes + 1, now()->addMinutes($lockMinutes + $decay));
            }

            Cache::put($this->lockKey($email, $ip), true, now()->addMinutes($lockMinutes));
            Cache::forget($countKey);
        }
    }

    public function throwIfLockedOut(string $email, ?string $ip = null): void
    {
        if (! $this->isLockedOut($email, $ip)) {
            return;
        }

        $decay = (int) config('auth_system.security.lockout.decay_minutes', 15);

        throw new AuthException(
            "Account temporarily locked due to too many failed attempts. Try again in {$decay} minute(s).",
            'account_locked',
            ['seconds' => $decay * 60],
        );
    }

    public function clear(string $email, ?string $ip = null): void
    {
        Cache::forget($this->countKey($email, $ip));
        Cache::forget($this->lockKey($email, $ip));
        Cache::forget($this->strikeKey($email, $ip));
    }
}
