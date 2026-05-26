<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Services;

use Illuminate\Support\Carbon;
use Joe404\LaravelAuth\Models\AuthTrustedDevice;

class TrustLevelResolver
{
    /**
     * Compute the effective trust level for a device using the configured
     * assignment mode. Stored level still wins for admin-granted high trust
     * (mode = time_admin) and for the registration device (which is created
     * with level=high directly).
     */
    public function resolve(AuthTrustedDevice $device): string
    {
        if ($device->revoked_at !== null) {
            return AuthTrustedDevice::LEVEL_UNTRUSTED;
        }

        if ($device->trusted_at === null) {
            return AuthTrustedDevice::LEVEL_UNTRUSTED;
        }

        if ($device->admin_granted && $device->level === AuthTrustedDevice::LEVEL_HIGH) {
            return AuthTrustedDevice::LEVEL_HIGH;
        }

        $mode = (string) config('auth_system.trusted_devices.level_assignment', 'time');

        $low    = (int) config('auth_system.trusted_devices.thresholds_days.low', 15);
        $medium = (int) config('auth_system.trusted_devices.thresholds_days.medium', 60);
        $high   = (int) config('auth_system.trusted_devices.thresholds_days.high', 90);

        $daysTrusted = $device->trusted_at instanceof Carbon
            ? max(0, (int) $device->trusted_at->diffInDays(now()))
            : 0;

        if ($mode === 'time_consistent') {
            $maxAbsence = (int) config('auth_system.trusted_devices.consistency.max_absence_days', 30);

            if ($device->last_seen_at instanceof Carbon
                && $device->last_seen_at->diffInDays(now()) > $maxAbsence
            ) {
                // Long absence resets progress to "trusted but low".
                return AuthTrustedDevice::LEVEL_LOW;
            }
        }

        if ($mode === 'time_admin') {
            // Time gives at most medium; high requires admin grant.
            if ($daysTrusted >= $medium) {
                return AuthTrustedDevice::LEVEL_MEDIUM;
            }

            if ($daysTrusted >= $low) {
                return AuthTrustedDevice::LEVEL_LOW;
            }

            return AuthTrustedDevice::LEVEL_LOW;
        }

        // mode = time | time_consistent
        if ($daysTrusted >= $high) {
            return AuthTrustedDevice::LEVEL_HIGH;
        }

        if ($daysTrusted >= $medium) {
            return AuthTrustedDevice::LEVEL_MEDIUM;
        }

        return AuthTrustedDevice::LEVEL_LOW;
    }

    public function bypassMinimum(): string
    {
        $level = (string) config('auth_system.trusted_devices.bypass_2fa_min_level', 'medium');

        return in_array($level, AuthTrustedDevice::LEVELS, true) ? $level : AuthTrustedDevice::LEVEL_MEDIUM;
    }

    public function canBypass(AuthTrustedDevice $device): bool
    {
        $effective = $this->resolve($device);

        return AuthTrustedDevice::rankOf($effective) >= AuthTrustedDevice::rankOf($this->bypassMinimum());
    }
}
