<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Services;

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Joe404\LaravelAuth\Events\PhoneVerified;
use Joe404\LaravelAuth\Exceptions\PhoneVerificationException;
use Joe404\LaravelAuth\Models\AuthPhoneOtpCode;
use Joe404\LaravelAuth\Phone\PhoneDriverManager;

class PhoneVerificationService
{
    public function __construct(
        private readonly PhoneDriverManager $drivers,
    ) {}

    /**
     * Issue and send a phone OTP. Invalidates any previous active codes for
     * the same (phone, purpose) pair.
     */
    public function sendCode(
        ?int $userId,
        string $phone,
        string $purpose = AuthPhoneOtpCode::PURPOSE_PHONE_VERIFY,
        ?string $channel = null,
    ): AuthPhoneOtpCode {
        $phone   = $this->normalizePhone($phone);
        $channel = $channel ?? (string) config('auth_system.phone.verification.default_channel', 'sms');
        $length  = max(4, min(10, (int) config('auth_system.phone.verification.otp_length', 6)));
        $expiry  = max(1, (int) config('auth_system.phone.verification.otp_expiry_minutes', 5));

        // Invalidate previous unconsumed codes for this purpose+phone.
        AuthPhoneOtpCode::query()
            ->where('phone', $phone)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        $plainCode = $this->generateNumericCode($length);

        $record = AuthPhoneOtpCode::create([
            'user_id'    => $userId,
            'phone'      => $phone,
            'purpose'    => $purpose,
            'code_hash'  => Hash::make($plainCode),
            'channel'    => $channel,
            'attempts'   => 0,
            'expires_at' => now()->addMinutes($expiry),
            'created_at' => now(),
        ]);

        $this->drivers->send($channel, $phone, $plainCode, [
            'minutes' => $expiry,
            'purpose' => $purpose,
        ]);

        return $record;
    }

    /**
     * Verify a code. On success, returns the AuthPhoneOtpCode row. On failure,
     * throws PhoneVerificationException with a specific error key.
     */
    public function verifyCode(
        string $phone,
        string $code,
        string $purpose = AuthPhoneOtpCode::PURPOSE_PHONE_VERIFY,
    ): AuthPhoneOtpCode {
        $phone       = $this->normalizePhone($phone);
        $maxAttempts = max(1, (int) config('auth_system.phone.verification.max_attempts', 5));

        $record = AuthPhoneOtpCode::query()
            ->where('phone', $phone)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->latest('id')
            ->first();

        if ($record === null) {
            throw new PhoneVerificationException('No active phone code for this number.', 'phone_otp_not_found');
        }

        if ($record->isExpired()) {
            throw new PhoneVerificationException('Phone code has expired.', 'phone_otp_expired');
        }

        if ($record->attempts >= $maxAttempts) {
            $record->update(['consumed_at' => now()]);

            throw new PhoneVerificationException('Too many attempts; request a new code.', 'phone_otp_locked');
        }

        if (! Hash::check($code, (string) $record->code_hash)) {
            $record->increment('attempts');

            throw new PhoneVerificationException('Invalid phone code.', 'phone_otp_invalid');
        }

        // Atomic single-use consume: only the request that flips consumed_at
        // from NULL wins (UPDATE ... WHERE consumed_at IS NULL is atomic per
        // row). A concurrent verify of the same valid code gets 0 affected
        // rows and is rejected. Same pattern as OtpService / BackupCodeService.
        $consumed = AuthPhoneOtpCode::where('id', $record->getKey())
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        if ($consumed === 0) {
            throw new PhoneVerificationException('Phone code has already been used.', 'phone_otp_invalid');
        }

        return $record;
    }

    /**
     * Mark the user's phone as verified after a successful code check.
     */
    public function markVerified(User $user, string $phone): void
    {
        $phone = $this->normalizePhone($phone);

        $column = (string) config('auth_system.phone.column', 'phone');

        $user->{$column}            = $phone;
        $user->phone_verified_at    = now();
        $user->save();

        PhoneVerified::dispatch($user, $phone);
    }

    public function normalizePhone(string $phone): string
    {
        $trimmed = trim($phone);

        if ($trimmed === '') {
            return $trimmed;
        }

        // Allow leading + and digits only.
        $cleaned = preg_replace('/[^\d+]/', '', $trimmed) ?? '';

        // Force exactly one leading + (or none).
        if (str_starts_with($cleaned, '+')) {
            $cleaned = '+' . preg_replace('/[^\d]/', '', substr($cleaned, 1));
        }

        return $cleaned;
    }

    private function generateNumericCode(int $length): string
    {
        $max  = (10 ** $length) - 1;
        $code = (string) random_int(0, $max);

        return str_pad($code, $length, '0', STR_PAD_LEFT);
    }
}
