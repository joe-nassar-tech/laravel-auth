<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Services;

use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Joe404\LaravelAuth\Events\TwoFactorChallengeFailed;
use Joe404\LaravelAuth\Exceptions\AuthException;
use Joe404\LaravelAuth\Exceptions\TwoFactorChallengeExpiredException;
use Joe404\LaravelAuth\Exceptions\TwoFactorChallengeInvalidException;
use Joe404\LaravelAuth\Exceptions\TwoFactorMethodNotEnrolledException;
use Joe404\LaravelAuth\Models\AuthTwoFactorChallenge;
use Joe404\LaravelAuth\Models\AuthTwoFactorMethod;

class TwoFactorChallengeService
{
    public function __construct(
        private readonly TwoFactorService $twoFactor,
    ) {}

    /**
     * Create a challenge for a user + immediately issue the code for the
     * preferred method (default method or first verified).
     *
     * @return array<string,mixed>
     */
    public function createForUser(User $user, ?string $clientType, Request $request): array
    {
        $method = $this->twoFactor->defaultMethod($user);

        if ($method === null) {
            throw new TwoFactorMethodNotEnrolledException('User has no 2FA methods enrolled.');
        }

        $ttl = max(60, (int) config('auth_system.two_factor.challenge.ttl_seconds', 300));

        // Reuse the latest unconsumed, unexpired challenge for this user so a
        // polled protected endpoint (or a refreshed login page) does not spawn
        // hundreds of challenge rows + rotate the token out from under the
        // client. We DO re-send the code so the user always has a fresh one.
        $challenge = AuthTwoFactorChallenge::query()
            ->where('user_id', $user->getKey())
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();

        $reused = $challenge !== null;

        // The challenge_token returned to the client is plaintext (a UUID); the
        // DB only ever stores its HMAC. On reuse we ROTATE the token (we don't
        // retain the original plaintext anywhere) but keep the same row — same
        // anti-spam guarantee, no row proliferation. The OTP/SMS/email code
        // already sent at row creation is still valid and is NOT re-sent here.
        $plainToken  = Str::uuid()->toString();
        $hashedToken = $this->hashChallengeToken($plainToken);

        if ($challenge === null) {
            $challenge = AuthTwoFactorChallenge::create([
                'challenge_token'  => $hashedToken,
                'user_id'          => $user->getKey(),
                'method'           => $method->type,
                'attempts'         => 0,
                'client_type'      => $clientType,
                'ip_address'       => $request->ip(),
                'fingerprint_hash' => (string) data_get($request->all(), '_device.fingerprint_hash'),
                'expires_at'       => now()->addSeconds($ttl),
                'created_at'       => now(),
            ]);

            $this->twoFactor->issueChallengeCode($method);
        } else {
            $challenge->update(['challenge_token' => $hashedToken]);
        }

        return [
            'challenge_token'   => $plainToken,
            'method'            => $challenge->method ?: $method->type,
            'expires_in'        => max(0, (int) now()->diffInSeconds($challenge->expires_at, false)),
            'available_methods' => $this->twoFactor->enrolledMethodTypes($user),
            'masked_target'     => $this->maskTarget($method, $user),
            'reused'            => $reused,
        ];
    }

    /**
     * Verify a challenge: try the supplied method code, then backup code as fallback.
     *
     * Returns the user on success. Throws on failure / expiry / lockout.
     */
    public function verify(string $challengeToken, string $code, ?string $methodHint = null): User
    {
        // Rate limit by challenge_token first — a leaked token must be
        // brute-force resistant regardless of attacker IP rotation. The
        // database attempt counter on the challenge row is the long-lived
        // signal (invalidates the challenge); this cache key throttles
        // burst attempts in the seconds-window.
        $rlKey = "auth:2fa:challenge_rl:" . hash('sha256', $challengeToken);

        // Cache::add seeds the key (with TTL) only if absent — this works on
        // every cache driver, including `database`, where Cache::increment()
        // on a missing key returns false and would otherwise leave the
        // counter permanently stuck at 0 (limiter silently disabled).
        Cache::add($rlKey, 0, now()->addMinute());
        $burst = (int) Cache::increment($rlKey);

        $burstMax = max(1, (int) config('auth_system.two_factor.challenge.burst_max_per_minute', 10));
        if ($burst > $burstMax) {
            throw new TwoFactorChallengeInvalidException('Too many attempts; slow down.');
        }

        $challenge = $this->loadUsable($challengeToken);

        $maxAttempts = max(1, (int) config('auth_system.two_factor.challenge.max_attempts', 5));

        if ($challenge->attempts >= $maxAttempts) {
            $challenge->update(['consumed_at' => now()]);

            throw new TwoFactorChallengeInvalidException('Too many failed attempts. Please log in again.');
        }

        /** @var User $user */
        $user = $challenge->user()->firstOrFail();

        $type = $methodHint ?: (string) $challenge->method;

        $method = AuthTwoFactorMethod::query()
            ->where('user_id', $user->getKey())
            ->where('type', $type)
            ->whereNotNull('verified_at')
            ->first();

        $ok = false;

        if ($method !== null) {
            $ok = $this->twoFactor->verifyChallenge($method, $code);
        }

        if (! $ok) {
            // Try backup code as last resort.
            $ok = $this->twoFactor->consumeBackupCode($user, $code);

            if ($ok) {
                $type = 'backup';
            }
        }

        if (! $ok) {
            $challenge->increment('attempts');

            TwoFactorChallengeFailed::dispatch($user, $type, $challenge->attempts + 1);

            throw new TwoFactorChallengeInvalidException('Invalid 2FA code.');
        }

        $challenge->update([
            'consumed_at' => now(),
            'method'      => $type,
        ]);

        return $user;
    }

    /**
     * Switch the active method on a challenge and re-send a fresh code.
     */
    public function switchMethod(string $challengeToken, string $newMethod): array
    {
        $challenge = $this->loadUsable($challengeToken);

        /** @var User $user */
        $user = $challenge->user()->firstOrFail();

        $method = AuthTwoFactorMethod::query()
            ->where('user_id', $user->getKey())
            ->where('type', $newMethod)
            ->whereNotNull('verified_at')
            ->first();

        if ($method === null) {
            throw new TwoFactorMethodNotEnrolledException("Method '{$newMethod}' is not enrolled.");
        }

        $challenge->update(['method' => $newMethod]);

        $this->twoFactor->issueChallengeCode($method);

        return [
            'challenge_token' => $challengeToken,
            'method'          => $newMethod,
            'expires_in'      => max(0, now()->diffInSeconds($challenge->expires_at, false)),
            'masked_target'   => $this->maskTarget($method, $user),
        ];
    }

    /**
     * Re-send the code for the currently-selected method.
     */
    public function resend(string $challengeToken): array
    {
        $challenge = $this->loadUsable($challengeToken);

        /** @var User $user */
        $user = $challenge->user()->firstOrFail();

        $method = AuthTwoFactorMethod::query()
            ->where('user_id', $user->getKey())
            ->where('type', (string) $challenge->method)
            ->whereNotNull('verified_at')
            ->first();

        if ($method === null) {
            throw new TwoFactorMethodNotEnrolledException('Active method is no longer enrolled.');
        }

        $this->twoFactor->issueChallengeCode($method);

        return [
            'challenge_token' => $challengeToken,
            'method'          => $method->type,
            'expires_in'      => max(0, now()->diffInSeconds($challenge->expires_at, false)),
            'masked_target'   => $this->maskTarget($method, $user),
        ];
    }

    private function loadUsable(string $challengeToken): AuthTwoFactorChallenge
    {
        $challenge = AuthTwoFactorChallenge::where('challenge_token', $this->hashChallengeToken($challengeToken))->first();

        if ($challenge === null || $challenge->isConsumed()) {
            throw new TwoFactorChallengeInvalidException('Invalid challenge.');
        }

        if ($challenge->isExpired()) {
            throw new TwoFactorChallengeExpiredException('Challenge expired.');
        }

        return $challenge;
    }

    /**
     * HMAC-SHA256 of a challenge_token using the app key as pepper. Mirrors
     * OtpService / BackupCodeService / TrustedDeviceService — the DB only ever
     * sees the digest; the plaintext lives only in the response returned to the
     * client at creation time.
     */
    private function hashChallengeToken(string $token): string
    {
        $key = (string) config('app.key', '');

        if (str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7), true) ?: $key;
        }

        return hash_hmac('sha256', $token, $key);
    }

    private function maskTarget(AuthTwoFactorMethod $method, User $user): ?string
    {
        return match ($method->type) {
            TwoFactorService::METHOD_EMAIL => $this->mask((string) $user->email, '@'),
            TwoFactorService::METHOD_SMS   => $this->maskPhone((string) ($user->phone ?? '')),
            default                         => null,
        };
    }

    private function mask(string $value, string $sep): string
    {
        $parts = explode($sep, $value, 2);
        if (count($parts) !== 2) {
            return $value;
        }

        $name = $parts[0];
        $mask = strlen($name) <= 2 ? $name : substr($name, 0, 2) . str_repeat('*', max(0, strlen($name) - 2));

        return $mask . $sep . $parts[1];
    }

    private function maskPhone(string $phone): string
    {
        if (strlen($phone) < 4) {
            return $phone;
        }

        return str_repeat('*', strlen($phone) - 4) . substr($phone, -4);
    }
}
