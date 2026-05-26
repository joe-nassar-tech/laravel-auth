<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Services;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Joe404\LaravelAuth\Contracts\CombinedOtpChannelContract;
use Joe404\LaravelAuth\Contracts\OtpChannelContract;
use Joe404\LaravelAuth\Exceptions\AuthConfigurationException;
use Joe404\LaravelAuth\Exceptions\OtpExpiredException;
use Joe404\LaravelAuth\Exceptions\OtpInvalidException;
use Joe404\LaravelAuth\Models\AuthOtpCode;

class OtpService
{
    public function __construct(
        private readonly OtpChannelContract $channel,
    ) {}

    public function sendOtp(string $email, string $type, string $tempToken = ''): string
    {
        if ($tempToken === '') {
            $tempToken = Str::uuid()->toString();
        }

        $this->invalidatePrevious($email, $type);

        [$otp, $expiryMinutes] = $this->generateOtp();

        AuthOtpCode::create([
            'user_id'    => null,
            'email'      => $email,
            'type'       => $type,
            'token'      => $this->hashToken($otp),
            'temp_token' => $tempToken,
            'expires_at' => now()->addMinutes($expiryMinutes),
        ]);

        $this->channel->send($email, $otp, $type, [
            'expires_in' => $expiryMinutes,
            'temp_token' => $tempToken,
        ]);

        return $tempToken;
    }

    public function sendMagicLink(string $email, string $type, string $tempToken = ''): string
    {
        if ($tempToken === '') {
            $tempToken = Str::uuid()->toString();
        }

        $this->invalidatePrevious($email, $type);

        [$link, $uuid, $magicExpiry] = $this->generateMagicLink($type);

        AuthOtpCode::create([
            'user_id'    => null,
            'email'      => $email,
            'type'       => $type,
            'token'      => $this->hashToken($uuid),
            'temp_token' => $tempToken,
            'expires_at' => now()->addMinutes($magicExpiry),
        ]);

        $this->channel->send($email, $link, $type, [
            'expires_in' => $magicExpiry,
        ]);

        return $tempToken;
    }

    public function sendCombined(string $email, string $otpType, string $magicType, string $tempToken = ''): string
    {
        if ($tempToken === '') {
            $tempToken = Str::uuid()->toString();
        }

        $this->invalidatePrevious($email, $otpType);
        $this->invalidatePrevious($email, $magicType);

        [$otp, $expiryMinutes] = $this->generateOtp();
        [$link, $uuid, $magicExpiry] = $this->generateMagicLink($magicType);

        // Use the shorter of the two expiries so neither claim is misleading
        $displayExpiry = min($expiryMinutes, $magicExpiry);

        AuthOtpCode::create([
            'user_id'    => null,
            'email'      => $email,
            'type'       => $otpType,
            'token'      => $this->hashToken($otp),
            'temp_token' => $tempToken,
            'expires_at' => now()->addMinutes($expiryMinutes),
        ]);

        AuthOtpCode::create([
            'user_id'    => null,
            'email'      => $email,
            'type'       => $magicType,
            'token'      => $this->hashToken($uuid),
            'temp_token' => $tempToken,
            'expires_at' => now()->addMinutes($magicExpiry),
        ]);

        $context = ['expires_in' => $displayExpiry, 'temp_token' => $tempToken];

        if ($this->channel instanceof CombinedOtpChannelContract) {
            // Channel supports a single combined delivery (one email with both OTP + link).
            $this->channel->sendCombined($email, $otp, $link, $otpType, $context);
        } else {
            // Fallback for custom channels that only implement OtpChannelContract:
            // send two separate messages so the user still receives both options.
            $this->channel->send($email, $otp, $otpType, $context);
            $this->channel->send($email, $link, $magicType, $context);
        }

        return $tempToken;
    }

    public function validateOtp(string $email, string $code, string $type): AuthOtpCode
    {
        $maxAttempts = (int) config('auth_system.verification.otp_max_attempts', 5);

        // Pull the most recent unused, unexpired OTP for this email/type to count
        // failed attempts against it. We do this WITHOUT matching on hash so a
        // wrong code still increments the active OTP's counter — otherwise an
        // attacker could probe forever without ever bumping the row.
        $active = AuthOtpCode::where('email', $email)
            ->where('type', $type)
            ->whereNull('used_at')
            ->latest('id')
            ->first();

        if ($active === null) {
            throw new OtpInvalidException();
        }

        if ($active->isExpired()) {
            throw new OtpExpiredException();
        }

        if (! hash_equals((string) $active->token, $this->hashToken($code))) {
            // Atomic increment so concurrent guesses cannot race past the limit.
            AuthOtpCode::where('id', $active->getKey())->increment('failed_attempts');

            if (($active->failed_attempts + 1) >= $maxAttempts) {
                AuthOtpCode::where('id', $active->getKey())->update(['used_at' => now()]);
            }

            throw new OtpInvalidException();
        }

        AuthOtpCode::where('id', $active->getKey())->update(['used_at' => now()]);

        return $active->fresh();
    }

    public function validateMagicLink(string $token, string $type): AuthOtpCode
    {
        $record = AuthOtpCode::where('token', $this->hashToken($token))
            ->where('type', $type)
            ->whereNull('used_at')
            ->latest('id')
            ->first();

        if ($record === null) {
            throw new OtpInvalidException();
        }

        if ($record->isExpired()) {
            throw new OtpExpiredException();
        }

        $record->update(['used_at' => now()]);

        return $record->fresh();
    }

    public function invalidatePrevious(string $email, string $type): void
    {
        AuthOtpCode::where('email', $email)
            ->where('type', $type)
            ->whereNull('used_at')
            ->update(['used_at' => now()]);
    }

    public function deleteExpired(): int
    {
        return AuthOtpCode::where('expires_at', '<', now())
            ->whereNull('used_at')
            ->delete();
    }

    /**
     * Keyed hash for OTP codes and magic-link UUIDs stored in auth_otp_codes.
     *
     * HMAC-SHA256 with the app key as pepper — a leaked database cannot be
     * used to brute-force the low-entropy numeric OTP space offline (plain
     * SHA-256 of a 6-digit code is reversible in milliseconds). Deterministic,
     * so the existing store-then-lookup-by-hash flow is unchanged. Mirrors
     * BackupCodeService's hashing. Note: any OTPs minted before deploying
     * this change will no longer validate (they hash differently) — they are
     * short-lived, so affected users simply request a new code.
     */
    private function hashToken(string $value): string
    {
        $key = (string) config('app.key', '');

        if (str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7), true) ?: $key;
        }

        return hash_hmac('sha256', $value, $key);
    }

    /** @return array{string, int} [code, expiryMinutes] */
    private function generateOtp(): array
    {
        $otpLength     = (int) config('auth_system.verification.otp_length', 6);
        $expiryMinutes = (int) config('auth_system.verification.otp_expiry', 10);

        // Clamp to a safe range — the AuthServiceProvider validates this on boot,
        // but we re-clamp here to avoid integer overflow on str_repeat('9', N).
        $otpLength = max(4, min(8, $otpLength));
        $max       = (int) str_repeat('9', $otpLength);

        $otp = str_pad(
            (string) random_int(0, $max),
            $otpLength,
            '0',
            STR_PAD_LEFT,
        );

        return [$otp, $expiryMinutes];
    }

    /** @return array{string, string, int} [link, uuid, expiryMinutes] */
    private function generateMagicLink(string $type): array
    {
        $magicExpiry = (int) config('auth_system.verification.magic_expiry', 30);
        $uuid        = Str::uuid()->toString();
        $target      = (string) config('auth_system.verification.magic_link_target', 'backend');

        if ($target === 'frontend') {
            $configKey = $type === 'magic_link_reset'
                ? 'auth_system.verification.frontend_reset_url'
                : 'auth_system.verification.frontend_verify_url';

            $base = trim((string) config($configKey, ''));

            if ($base === '' || filter_var($base, FILTER_VALIDATE_URL) === false) {
                throw new AuthConfigurationException(
                    "Magic-link target is set to 'frontend' but [{$configKey}] is missing or not a valid URL.",
                    'magic_link_frontend_url_missing',
                );
            }

            $link = rtrim($base, '/') . '?token=' . $uuid;
        } else {
            $routeName = match ($type) {
                'magic_link_reset' => 'auth.password.reset.magic',
                default            => 'auth.register.verify.magic',
            };

            $link = URL::temporarySignedRoute(
                $routeName,
                now()->addMinutes($magicExpiry),
                ['token' => $uuid],
            );
        }

        return [$link, $uuid, $magicExpiry];
    }
}
