<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Services;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Joe404\LaravelAuth\Contracts\OtpChannelContract;
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

        $otpLength = (int) config('auth_system.verification.otp_length', 6);
        $otp       = str_pad(
            (string) random_int(0, (int) str_repeat('9', $otpLength)),
            $otpLength,
            '0',
            STR_PAD_LEFT,
        );

        $expiryMinutes = (int) config('auth_system.verification.otp_expiry', 10);

        AuthOtpCode::create([
            'user_id'    => null,
            'email'      => $email,
            'type'       => $type,
            'token'      => $otp,
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

        $uuid = Str::uuid()->toString();

        $magicExpiry = (int) config('auth_system.verification.magic_expiry', 30);

        $routeName = match ($type) {
            'magic_link_verify' => 'auth.register.verify.magic',
            'magic_link_reset'  => 'auth.password.reset.magic',
            default             => 'auth.register.verify.magic',
        };

        $signedUrl = URL::temporarySignedRoute(
            $routeName,
            now()->addMinutes($magicExpiry),
            ['token' => $uuid],
        );

        AuthOtpCode::create([
            'user_id'    => null,
            'email'      => $email,
            'type'       => $type,
            'token'      => $uuid,
            'temp_token' => $tempToken,
            'expires_at' => now()->addMinutes($magicExpiry),
        ]);

        $this->channel->send($email, $signedUrl, $type, [
            'expires_in' => $magicExpiry,
        ]);

        return $tempToken;
    }

    public function validateOtp(string $email, string $code, string $type): AuthOtpCode
    {
        $record = AuthOtpCode::where('email', $email)
            ->where('type', $type)
            ->where('token', $code)
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

    public function validateMagicLink(string $token, string $type): AuthOtpCode
    {
        $record = AuthOtpCode::where('token', $token)
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
}
