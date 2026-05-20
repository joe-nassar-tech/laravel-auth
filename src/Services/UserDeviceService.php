<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Services;

use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Joe404\LaravelAuth\Models\AuthUserDevice;

/**
 * Upserts a permanent record per (user, device) every time a session is
 * created. Survives logout, powers both the referral anti-abuse system
 * and the GET /auth/devices endpoint that lets the user see who's been
 * logging into their account.
 */
class UserDeviceService
{
    public function __construct(
        private readonly DeviceService $deviceService,
    ) {}

    /**
     * Idempotent record-or-touch. Called whenever a new session is
     * created (login, registration, social auth, password reset
     * auto-login). Existing rows have their last_seen_at + location +
     * IP refreshed; new rows are inserted.
     */
    public function record(User $user, Request $request): AuthUserDevice
    {
        /** @var array<string, mixed> $fp */
        $fp = $request->get('_device', []);

        if (empty($fp)) {
            $fp = $this->deviceService->fingerprint($request);
        }

        $signature = $this->computeSignature($fp);
        $now       = now();

        /** @var AuthUserDevice|null $existing */
        $existing = AuthUserDevice::where('user_id', $user->getKey())
            ->where('device_signature', $signature)
            ->first();

        if ($existing !== null) {
            // Touch only — overwrite mutable fields, keep first_seen_at.
            $existing->update([
                'fingerprint_hash'      => $fp['fingerprint_hash'] ?? $existing->fingerprint_hash,
                'ip_address'            => $fp['ip_address'] ?? $request->ip(),
                'platform'              => $fp['platform'] ?? $existing->platform,
                'browser'               => $fp['browser'] ?? $existing->browser,
                'os'                    => $fp['os'] ?? $existing->os,
                'device_model'          => $fp['device_model'] ?? $existing->device_model,
                'device_marketing_name' => $fp['device_marketing_name'] ?? $existing->device_marketing_name,
                'device_code'           => $fp['device_code'] ?? $existing->device_code,
                'device_platform'       => $fp['device_platform'] ?? $existing->device_platform,
                'country'               => $fp['country'] ?? $existing->country,
                'city'                  => $fp['city'] ?? $existing->city,
                'last_seen_at'          => $now,
            ]);

            return $existing->refresh();
        }

        return AuthUserDevice::create([
            'user_id'               => $user->getKey(),
            'fingerprint_hash'      => $fp['fingerprint_hash'] ?? null,
            'device_signature'      => $signature,
            'ip_address'            => $fp['ip_address'] ?? $request->ip(),
            'platform'              => $fp['platform'] ?? 'web',
            'browser'               => $fp['browser'] ?? null,
            'os'                    => $fp['os'] ?? null,
            'device_model'          => $fp['device_model'] ?? null,
            'device_marketing_name' => $fp['device_marketing_name'] ?? null,
            'device_code'           => $fp['device_code'] ?? null,
            'device_platform'       => $fp['device_platform'] ?? null,
            'country'               => $fp['country'] ?? null,
            'city'                  => $fp['city'] ?? null,
            'first_seen_at'         => $now,
            'last_seen_at'          => $now,
        ]);
    }

    /**
     * Build a stable per-device signature so the table does not grow a
     * new row on every login. Priority order:
     *
     *   1. fingerprint_hash (strong) — same device hash means same row
     *   2. device_code (mobile model + t2s) — distinguishes phones
     *   3. browser + os + platform (weak) — best we can do without JS
     *
     * The signature is never user-visible; it only exists to enable the
     * uniqueness constraint on (user_id, device_signature).
     *
     * @param array<string, mixed> $fp
     */
    private function computeSignature(array $fp): string
    {
        $hash = isset($fp['fingerprint_hash']) && is_string($fp['fingerprint_hash']) && $fp['fingerprint_hash'] !== ''
            ? (string) $fp['fingerprint_hash']
            : null;

        if ($hash !== null) {
            return 'h:' . substr($hash, 0, 180);
        }

        $deviceCode = isset($fp['device_code']) ? (string) $fp['device_code'] : '';

        if ($deviceCode !== '') {
            return 'd:' . substr(hash('sha256', $deviceCode), 0, 64);
        }

        $weak = implode('|', [
            (string) ($fp['platform'] ?? ''),
            (string) ($fp['browser'] ?? ''),
            (string) ($fp['os'] ?? ''),
        ]);

        return 'w:' . substr(hash('sha256', $weak), 0, 64);
    }

    /**
     * Lookup helpers used by the referral abuse check.
     */
    public function userHasDeviceWithFingerprint(int $userId, ?string $fingerprintHash): bool
    {
        if ($fingerprintHash === null || $fingerprintHash === '') {
            return false;
        }

        return AuthUserDevice::where('user_id', $userId)
            ->where('fingerprint_hash', $fingerprintHash)
            ->exists();
    }

    public function userHasDeviceWithIp(int $userId, ?string $ip): bool
    {
        if ($ip === null || $ip === '') {
            return false;
        }

        return AuthUserDevice::where('user_id', $userId)
            ->where('ip_address', $ip)
            ->exists();
    }

    /**
     * The most-recently-seen device row for a user. Used as a fallback
     * audit snapshot for the referral row (so admins can see "the
     * referrer's most recent device looked like X" even when the new
     * user's fingerprint matched a different historical row).
     */
    public function mostRecent(int $userId): ?AuthUserDevice
    {
        /** @var AuthUserDevice|null $device */
        $device = AuthUserDevice::where('user_id', $userId)
            ->latest('last_seen_at')
            ->first();

        return $device;
    }

    /**
     * @return Collection<int, AuthUserDevice>
     */
    public function listForUser(int $userId): Collection
    {
        return AuthUserDevice::where('user_id', $userId)
            ->latest('last_seen_at')
            ->get();
    }
}
