<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Services;

use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Joe404\LaravelAuth\Events\TrustedDeviceAdded;
use Joe404\LaravelAuth\Events\TrustedDeviceRevoked;
use Joe404\LaravelAuth\Exceptions\AuthException;
use Joe404\LaravelAuth\Models\AuthTrustedDevice;

class TrustedDeviceService
{
    public function __construct(
        private readonly TrustLevelResolver $resolver,
    ) {}

    public function isEnabled(): bool
    {
        return (bool) config('auth_system.trusted_devices.enabled', true);
    }

    /**
     * Record (or touch) the current request's device for the user. Does NOT
     * mark it as trusted — that requires user opt-in (trustCurrent) or the
     * registration-device auto-trust branch.
     */
    public function touch(User $user, Request $request): ?AuthTrustedDevice
    {
        if (! $this->isEnabled()) {
            return null;
        }

        $fp = $this->fingerprint($request);

        if ($fp === null) {
            return null;
        }

        /** @var array<string,mixed> $device */
        $device = (array) $request->get('_device', []);

        $record = AuthTrustedDevice::query()
            ->where('user_id', $user->getKey())
            ->where('fingerprint_hash', $fp)
            ->first();

        if ($record === null) {
            $record = AuthTrustedDevice::create([
                'user_id'          => $user->getKey(),
                'fingerprint_hash' => $fp,
                'device_name'      => $device['device_marketing_name'] ?? $device['device_model'] ?? null,
                'platform'         => $device['platform']    ?? null,
                'browser'          => $device['browser']     ?? null,
                'os'               => $device['os']          ?? null,
                'ip_address'       => $device['ip_address']  ?? $request->ip(),
                'level'            => AuthTrustedDevice::LEVEL_LOW,
                'admin_granted'    => false,
                'first_seen_at'    => now(),
                'last_seen_at'     => now(),
            ]);
        } else {
            $record->update([
                'last_seen_at' => now(),
                'ip_address'   => $device['ip_address'] ?? $request->ip(),
            ]);
        }

        return $record;
    }

    /**
     * Auto-trust the registration device at level=high.
     *
     * Returns the device with a transient `plain_secret` attribute when a
     * fresh secret was issued. Callers must read that attribute and forward
     * it to the client (it is never returned again).
     */
    public function autoTrustRegistrationDevice(User $user, Request $request): ?AuthTrustedDevice
    {
        if (! $this->isEnabled()) {
            return null;
        }

        if (! (bool) config('auth_system.trusted_devices.auto_trust_registration_device', true)) {
            return $this->touch($user, $request);
        }

        $device = $this->touch($user, $request);

        if ($device === null) {
            return null;
        }

        $plain = $this->generateSecret();
        $level = $this->registrationDeviceLevel();

        $device->update([
            'level'       => $level,
            'trusted_at'  => now(),
            'secret_hash' => $this->hashSecret($plain),
        ]);

        TrustedDeviceAdded::dispatch($user, $device->id, $level);

        $device = $device->refresh();
        $device->setAttribute('plain_secret', $plain);

        return $device;
    }

    /**
     * User-initiated "trust this device" after a successful 2FA challenge.
     *
     * Returns the device with a transient `plain_secret` attribute when a
     * fresh secret was issued. Re-trust of an already-trusted device with
     * a known-good secret is a no-op (no rotation, no new plaintext).
     */
    public function trustCurrent(User $user, Request $request): ?AuthTrustedDevice
    {
        if (! $this->isEnabled()) {
            return null;
        }

        $device = $this->touch($user, $request);

        if ($device === null) {
            return null;
        }

        // Re-trust resurrects a previously revoked device. Without this branch,
        // a user who revoked-all and then completed 2FA again from the same
        // physical device would stay untrusted indefinitely because touch()
        // does not reset revoked_at.
        $needsTrust = $device->trusted_at === null
            || $device->revoked_at !== null
            || $device->secret_hash === null;

        if ($needsTrust) {
            $plain = $this->generateSecret();

            $device->update([
                'level'       => AuthTrustedDevice::LEVEL_LOW,
                'trusted_at'  => now(),
                'revoked_at'  => null,
                'secret_hash' => $this->hashSecret($plain),
            ]);

            TrustedDeviceAdded::dispatch($user, $device->id, AuthTrustedDevice::LEVEL_LOW);

            $device = $device->refresh();
            $device->setAttribute('plain_secret', $plain);

            return $device;
        }

        return $device->refresh();
    }

    /**
     * Returns the active trusted device record for the current request, or
     * null. "Active" means trusted_at set, not revoked, fingerprint matches,
     * AND the client supplied a valid X-Trusted-Device-Token header whose
     * SHA-256 matches the stored secret_hash.
     *
     * Fingerprint is a client-controlled signal — a stolen fingerprint must
     * NOT, by itself, grant 2FA bypass. The server-issued device token is
     * issued exactly once at trust time and is the real proof.
     */
    public function currentActive(User $user, Request $request): ?AuthTrustedDevice
    {
        if (! $this->isEnabled()) {
            return null;
        }

        $fp = $this->fingerprint($request);

        if ($fp === null) {
            return null;
        }

        $headerName = (string) config('auth_system.trusted_devices.token_header', 'X-Trusted-Device-Token');
        $token      = trim((string) $request->header($headerName, ''));

        if ($token === '') {
            return null;
        }

        $device = AuthTrustedDevice::query()
            ->where('user_id', $user->getKey())
            ->where('fingerprint_hash', $fp)
            ->where('secret_hash', $this->hashSecret($token))
            ->whereNotNull('trusted_at')
            ->whereNull('revoked_at')
            ->first();

        if ($device === null) {
            return null;
        }

        if ($device->expires_at !== null && $device->expires_at->isPast()) {
            return null;
        }

        return $device;
    }

    public function effectiveLevel(AuthTrustedDevice $device): string
    {
        return $this->resolver->resolve($device);
    }

    public function shouldBypass2fa(User $user, Request $request): bool
    {
        $device = $this->currentActive($user, $request);

        if ($device === null) {
            return false;
        }

        return $this->resolver->canBypass($device);
    }

    /**
     * Revoke a specific trusted device. Caller must already have run the
     * revocation-matrix check (revokerCanRevoke).
     */
    public function revoke(User $user, int $deviceId, string $reason = 'user'): void
    {
        $device = AuthTrustedDevice::query()
            ->where('user_id', $user->getKey())
            ->where('id', $deviceId)
            ->first();

        if ($device === null) {
            throw new AuthException('Trusted device not found.', 'trusted_device_not_found');
        }

        $device->update(['revoked_at' => now()]);

        TrustedDeviceRevoked::dispatch($user, $deviceId, $reason);
    }

    public function revokeAll(User $user, string $reason = 'user'): int
    {
        $count = AuthTrustedDevice::query()
            ->where('user_id', $user->getKey())
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);

        TrustedDeviceRevoked::dispatch($user, 0, $reason . '_all');

        return (int) $count;
    }

    /**
     * Enforces the revocation matrix:
     *   untrusted/low  → cannot revoke any
     *   medium         → can revoke low
     *   high           → can revoke low + medium
     *   revoke-all is permitted for ANY trusted device (low|medium|high).
     */
    public function revokerCanRevoke(?AuthTrustedDevice $actor, AuthTrustedDevice $target): bool
    {
        if ($actor === null || ! $actor->isActive()) {
            return false;
        }

        $actorLevel  = $this->resolver->resolve($actor);
        $targetLevel = $this->resolver->resolve($target);

        // Same device can always be revoked.
        if ($actor->id === $target->id) {
            return $actorLevel !== AuthTrustedDevice::LEVEL_UNTRUSTED;
        }

        if ($actorLevel === AuthTrustedDevice::LEVEL_HIGH) {
            return in_array($targetLevel, [AuthTrustedDevice::LEVEL_LOW, AuthTrustedDevice::LEVEL_MEDIUM], true)
                || $targetLevel === AuthTrustedDevice::LEVEL_HIGH; // high can revoke another high too
        }

        if ($actorLevel === AuthTrustedDevice::LEVEL_MEDIUM) {
            return $targetLevel === AuthTrustedDevice::LEVEL_LOW;
        }

        return false;
    }

    /**
     * Any trusted (non-untrusted) device may trigger revoke-all.
     */
    public function revokerCanRevokeAll(?AuthTrustedDevice $actor): bool
    {
        if ($actor === null) {
            return false;
        }

        return $this->resolver->resolve($actor) !== AuthTrustedDevice::LEVEL_UNTRUSTED;
    }

    /**
     * Initial level recorded for the registration device. The EFFECTIVE level
     * that governs 2FA bypass is still recomputed from elapsed time by
     * TrustLevelResolver — a brand-new device resolves to 'low' regardless of
     * this stored value (only an admin-granted 'high' short-circuits time), so
     * a freshly-registered device does not bypass 2FA under the default
     * bypass_2fa_min_level. This knob sets the stored starting level.
     */
    private function registrationDeviceLevel(): string
    {
        $level = (string) config('auth_system.trusted_devices.registration_device_level', AuthTrustedDevice::LEVEL_HIGH);

        return in_array($level, AuthTrustedDevice::LEVELS, true)
            ? $level
            : AuthTrustedDevice::LEVEL_HIGH;
    }

    private function generateSecret(): string
    {
        return bin2hex(random_bytes(32));
    }

    private function hashSecret(string $plain): string
    {
        return hash('sha256', $plain);
    }

    private function fingerprint(Request $request): ?string
    {
        /** @var array<string,mixed> $device */
        $device = (array) $request->get('_device', []);

        $hash = $device['fingerprint_hash'] ?? null;

        if (! is_string($hash) || $hash === '') {
            return null;
        }

        return $hash;
    }
}
