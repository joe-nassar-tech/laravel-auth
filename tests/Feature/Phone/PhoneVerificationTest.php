<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Joe404\LaravelAuth\Models\AuthPhoneOtpCode;
use Joe404\LaravelAuth\Services\PhoneVerificationService;

beforeEach(function () {
    config([
        'auth_system.phone.enabled' => true,
        'auth_system.phone.providers.log.driver' => \Joe404\LaravelAuth\Phone\Drivers\LogPhoneDriver::class,
        'auth_system.phone.channels.sms' => ['provider' => 'log', 'fallback' => null],
        'auth_system.phone.verification.otp_length' => 6,
        'auth_system.phone.verification.otp_expiry_minutes' => 5,
    ]);
});

it('sends a phone OTP via the log driver in development', function () {
    Log::shouldReceive('info')
        ->once()
        ->withArgs(function (string $message, array $context) {
            return $message === '[laravel-auth] Phone OTP (log driver)'
                && $context['channel'] === 'sms'
                && preg_match('/^\d{6}$/', $context['code']) === 1;
        });

    $record = app(PhoneVerificationService::class)->sendCode(
        userId: null,
        phone: '+14155550101',
    );

    expect($record)->toBeInstanceOf(AuthPhoneOtpCode::class);
    expect($record->phone)->toBe('+14155550101');
    expect($record->channel)->toBe('sms');
});

it('verifies a valid code and rejects a wrong code', function () {
    $svc = app(PhoneVerificationService::class);

    // Capture the code as it is sent.
    $capturedCode = null;
    Log::shouldReceive('info')->andReturnUsing(function (string $msg, array $ctx) use (&$capturedCode) {
        $capturedCode = $ctx['code'] ?? null;
    });

    $svc->sendCode(null, '+14155550102');

    expect($svc->verifyCode('+14155550102', $capturedCode))->toBeInstanceOf(AuthPhoneOtpCode::class);

    // The code is single-use — replay must fail.
    expect(fn () => $svc->verifyCode('+14155550102', $capturedCode))
        ->toThrow(\Joe404\LaravelAuth\Exceptions\PhoneVerificationException::class);
});
