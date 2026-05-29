<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Services;

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Joe404\LaravelAuth\Events\TwoFactorEnrolled;
use Joe404\LaravelAuth\Events\TwoFactorDisabled;
use Joe404\LaravelAuth\Exceptions\AuthException;
use Joe404\LaravelAuth\Exceptions\TwoFactorMethodNotEnrolledException;
use Joe404\LaravelAuth\Models\AuthPhoneOtpCode;
use Joe404\LaravelAuth\Models\AuthTwoFactorMethod;

class TwoFactorService
{
    public const METHOD_TOTP  = 'totp';
    public const METHOD_EMAIL = 'email';
    public const METHOD_SMS   = 'sms';

    public function __construct(
        private readonly TotpService $totp,
        private readonly OtpService $otpService,
        private readonly PhoneVerificationService $phoneService,
        private readonly BackupCodeService $backupCodes,
    ) {}

    // ---- Enrollment ---------------------------------------------------------

    /**
     * Begin enrollment for a method. For TOTP we generate a secret and return
     * it (along with an otpauth URI + SVG QR). For email/SMS we send a code.
     *
     * @return array<string,mixed>
     */
    public function startEnrollment(User $user, string $type): array
    {
        $this->assertMethodAllowed($type);

        $method = AuthTwoFactorMethod::firstOrNew([
            'user_id' => $user->getKey(),
            'type'    => $type,
        ]);

        // Wipe any prior unverified secret on restart.
        $method->secret_encrypted = null;
        $method->verified_at      = null;

        if ($type === self::METHOD_TOTP) {
            $secret = $this->totp->generateSecret();
            $method->secret_encrypted = Crypt::encryptString($secret);
            $method->save();

            $otpauthUri = $this->totp->otpauthUri((string) $user->email, $secret);

            return [
                'type'           => $type,
                'secret'         => $secret,
                'otpauth_uri'    => $otpauthUri,
                'qr_svg'         => $this->totp->qrSvg($otpauthUri),
                'digits'         => (int) config('auth_system.two_factor.codes.totp.digits', 6),
                'period_seconds' => (int) config('auth_system.two_factor.codes.totp.period', 30),
            ];
        }

        if ($type === self::METHOD_EMAIL) {
            $method->save();

            $tempToken = Str::uuid()->toString();
            $this->otpService->sendOtp((string) $user->email, 'two_factor_email_enroll', $tempToken);
            Cache::put($this->cacheKey('email_enroll', $user->getKey()), $tempToken, now()->addMinutes(
                (int) config('auth_system.two_factor.codes.email.expiry_minutes', 10),
            ));

            return [
                'type'           => $type,
                'sent_to'        => $this->maskEmail((string) $user->email),
                'expires_in'     => (int) config('auth_system.two_factor.codes.email.expiry_minutes', 10) * 60,
            ];
        }

        if ($type === self::METHOD_SMS) {
            $phone = (string) ($user->phone ?? '');

            if ($phone === '' || $user->phone_verified_at === null) {
                throw new AuthException(
                    'A verified phone is required before enrolling in SMS 2FA.',
                    'two_factor_sms_requires_verified_phone',
                );
            }

            $method->save();

            $this->phoneService->sendCode(
                $user->getKey(),
                $phone,
                AuthPhoneOtpCode::PURPOSE_TWO_FACTOR,
                (string) config('auth_system.two_factor.codes.sms.channel', 'sms'),
            );

            return [
                'type'       => $type,
                'sent_to'    => $this->maskPhone($phone),
                'expires_in' => (int) config('auth_system.two_factor.codes.sms.expiry_minutes', 5) * 60,
            ];
        }

        throw new AuthException("Unknown 2FA method: {$type}", 'two_factor_method_unknown');
    }

    public function verifyEnrollment(User $user, string $type, string $code): AuthTwoFactorMethod
    {
        $this->assertMethodAllowed($type);

        $method = AuthTwoFactorMethod::query()
            ->where('user_id', $user->getKey())
            ->where('type', $type)
            ->first();

        if ($method === null) {
            throw new TwoFactorMethodNotEnrolledException('Start enrollment first.');
        }

        $ok = match ($type) {
            self::METHOD_TOTP  => $this->verifyTotp($method, $code),
            self::METHOD_EMAIL => $this->verifyEmailCode($user, $code),
            self::METHOD_SMS   => $this->verifySmsCode($user, $code),
            default            => false,
        };

        if (! $ok) {
            throw new AuthException('Invalid 2FA code.', 'two_factor_code_invalid');
        }

        $isFirstVerified = ! $this->hasAnyVerifiedMethod($user);

        $method->verified_at = now();

        // First verified method becomes the default automatically.
        if ($isFirstVerified) {
            $method->is_default = true;
        }

        $method->save();

        $plainBackupCodes = null;

        // First-ever 2FA enrollment generates the backup code set.
        if ($isFirstVerified && (bool) config('auth_system.two_factor.backup_codes.enabled', true)) {
            $plainBackupCodes = $this->backupCodes->generate($user);
        }

        TwoFactorEnrolled::dispatch($user, $type);

        $method->setAttribute('backup_codes', $plainBackupCodes);

        return $method;
    }

    public function disable(User $user, int $methodId): void
    {
        $method = AuthTwoFactorMethod::query()
            ->where('user_id', $user->getKey())
            ->where('id', $methodId)
            ->first();

        if ($method === null) {
            throw new TwoFactorMethodNotEnrolledException('Method not found.');
        }

        $type = (string) $method->type;
        $method->delete();

        // If we just removed the default, promote any remaining method.
        if ($method->is_default) {
            $next = AuthTwoFactorMethod::query()
                ->where('user_id', $user->getKey())
                ->whereNotNull('verified_at')
                ->first();

            if ($next !== null) {
                $next->update(['is_default' => true]);
            }
        }

        // No verified methods remain → drop the backup codes.
        if (! $this->hasAnyVerifiedMethod($user)) {
            \Joe404\LaravelAuth\Models\AuthTwoFactorBackupCode::where('user_id', $user->getKey())->delete();
        }

        TwoFactorDisabled::dispatch($user, $type);
    }

    public function setDefault(User $user, int $methodId): AuthTwoFactorMethod
    {
        $method = AuthTwoFactorMethod::query()
            ->where('user_id', $user->getKey())
            ->where('id', $methodId)
            ->whereNotNull('verified_at')
            ->first();

        if ($method === null) {
            throw new TwoFactorMethodNotEnrolledException('Method not found or not verified.');
        }

        AuthTwoFactorMethod::query()
            ->where('user_id', $user->getKey())
            ->update(['is_default' => false]);

        $method->update(['is_default' => true]);

        return $method;
    }

    // ---- Challenge issuance + verification ---------------------------------

    /**
     * Push a fresh challenge code for an enrolled method (login flow).
     * TOTP is a no-op (user reads the code from their app).
     */
    public function issueChallengeCode(AuthTwoFactorMethod $method): void
    {
        $user = $method->user()->firstOrFail();

        switch ($method->type) {
            case self::METHOD_TOTP:
                // No-op
                return;

            case self::METHOD_EMAIL:
                $tempToken = Str::uuid()->toString();
                $this->otpService->sendOtp((string) $user->email, 'two_factor_email', $tempToken);
                Cache::put($this->cacheKey('email_challenge', (int) $user->getKey()), $tempToken, now()->addMinutes(
                    (int) config('auth_system.two_factor.codes.email.expiry_minutes', 10),
                ));

                return;

            case self::METHOD_SMS:
                $phone = (string) ($user->phone ?? '');

                if ($phone === '') {
                    throw new AuthException('No phone on file for SMS 2FA.', 'two_factor_sms_no_phone');
                }

                $this->phoneService->sendCode(
                    $user->getKey(),
                    $phone,
                    AuthPhoneOtpCode::PURPOSE_TWO_FACTOR,
                    (string) config('auth_system.two_factor.codes.sms.channel', 'sms'),
                );

                return;
        }
    }

    /**
     * Verify a challenge code against an enrolled method.
     */
    public function verifyChallenge(AuthTwoFactorMethod $method, string $code): bool
    {
        $user = $method->user()->firstOrFail();

        $ok = match ($method->type) {
            self::METHOD_TOTP  => $this->verifyTotp($method, $code),
            self::METHOD_EMAIL => $this->verifyEmailCode($user, $code),
            self::METHOD_SMS   => $this->verifySmsCode($user, $code),
            default            => false,
        };

        if ($ok) {
            $method->update(['last_used_at' => now()]);
        }

        return $ok;
    }

    public function consumeBackupCode(User $user, string $code): bool
    {
        return $this->backupCodes->consume($user, $code);
    }

    // ---- Read helpers ------------------------------------------------------

    public function hasAnyVerifiedMethod(User $user): bool
    {
        return AuthTwoFactorMethod::query()
            ->where('user_id', $user->getKey())
            ->whereNotNull('verified_at')
            ->exists();
    }

    public function defaultMethod(User $user): ?AuthTwoFactorMethod
    {
        return AuthTwoFactorMethod::query()
            ->where('user_id', $user->getKey())
            ->whereNotNull('verified_at')
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();
    }

    /** @return array<int,string> */
    public function enrolledMethodTypes(User $user): array
    {
        return AuthTwoFactorMethod::query()
            ->where('user_id', $user->getKey())
            ->whereNotNull('verified_at')
            ->pluck('type')
            ->all();
    }

    // ---- Internals ---------------------------------------------------------

    private function verifyTotp(AuthTwoFactorMethod $method, string $code): bool
    {
        if ($method->secret_encrypted === null) {
            return false;
        }

        try {
            $secret = Crypt::decryptString((string) $method->secret_encrypted);
        } catch (\Throwable) {
            return false;
        }

        $afterTimestep = $method->last_totp_timestep !== null
            ? (int) $method->last_totp_timestep
            : null;

        $timestep = $this->totp->verifyReturningTimestep($secret, trim($code), $afterTimestep);

        if ($timestep === false) {
            return false;
        }

        // Replay protection (RFC 6238 §5.2) under concurrency: only the
        // request that STRICTLY ADVANCES last_totp_timestep wins. Two
        // simultaneous verifies of the same in-window code compute the same
        // matched step → only the first conditional update succeeds, the
        // second affects 0 rows and is rejected. Also enforces monotonic
        // step advancement (an older step can never regress the column).
        $advanced = AuthTwoFactorMethod::where('id', $method->getKey())
            ->where(function ($q) use ($timestep) {
                $q->whereNull('last_totp_timestep')
                  ->orWhere('last_totp_timestep', '<', $timestep);
            })
            ->update(['last_totp_timestep' => $timestep]);

        return $advanced === 1;
    }

    private function verifyEmailCode(User $user, string $code): bool
    {
        // Both purposes are valid: 'two_factor_email_enroll' is set during
        // enrollment, 'two_factor_email' during login challenge. The user
        // can only have one active OTP row at a time per purpose.
        foreach (['two_factor_email', 'two_factor_email_enroll'] as $purpose) {
            try {
                $this->otpService->validateOtp((string) $user->email, $code, $purpose);

                return true;
            } catch (\Throwable) {
                continue;
            }
        }

        return false;
    }

    private function verifySmsCode(User $user, string $code): bool
    {
        $phone = (string) ($user->phone ?? '');

        if ($phone === '') {
            return false;
        }

        try {
            $this->phoneService->verifyCode($phone, $code, AuthPhoneOtpCode::PURPOSE_TWO_FACTOR);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function assertMethodAllowed(string $type): void
    {
        $allowed = (array) config('auth_system.two_factor.methods', ['totp', 'email', 'sms']);

        if (! in_array($type, $allowed, true)) {
            throw new AuthException("2FA method '{$type}' is not enabled.", 'two_factor_method_disabled');
        }
    }

    private function cacheKey(string $purpose, int $userId): string
    {
        return "auth:2fa:{$purpose}:{$userId}";
    }

    private function maskEmail(string $email): string
    {
        $parts = explode('@', $email, 2);

        if (count($parts) !== 2) {
            return $email;
        }

        $name = $parts[0];
        $mask = strlen($name) <= 2 ? $name : substr($name, 0, 2) . str_repeat('*', max(0, strlen($name) - 2));

        return $mask . '@' . $parts[1];
    }

    private function maskPhone(string $phone): string
    {
        if (strlen($phone) < 4) {
            return $phone;
        }

        return str_repeat('*', strlen($phone) - 4) . substr($phone, -4);
    }
}
