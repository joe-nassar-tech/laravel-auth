<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Notification;
use Joe404\LaravelAuth\Exceptions\OtpExpiredException;
use Joe404\LaravelAuth\Exceptions\OtpInvalidException;
use Joe404\LaravelAuth\Models\AuthOtpCode;
use Joe404\LaravelAuth\Services\OtpService;

beforeEach(function (): void {
    Notification::fake();
});

it('generates an OTP of the correct configured length', function (): void {
    config(['auth_system.verification.otp_length' => 6]);

    /** @var OtpService $otpService */
    $otpService = app(OtpService::class);

    $email = 'otp-length@example.com';
    $otpService->sendOtp($email, 'email_verify');

    $record = AuthOtpCode::where('email', $email)->where('type', 'email_verify')->latest()->first();

    expect($record)->not->toBeNull();
    expect(strlen($record->token))->toBe(6);
    expect(ctype_digit($record->token))->toBeTrue();
});

it('throws OtpInvalidException for a wrong OTP code', function (): void {
    /** @var OtpService $otpService */
    $otpService = app(OtpService::class);

    $email = 'invalid-otp@example.com';

    AuthOtpCode::create([
        'email'      => $email,
        'type'       => 'email_verify',
        'token'      => '111111',
        'temp_token' => \Illuminate\Support\Str::uuid()->toString(),
        'expires_at' => now()->addMinutes(10),
    ]);

    expect(fn () => $otpService->validateOtp($email, '999999', 'email_verify'))
        ->toThrow(OtpInvalidException::class);
});

it('throws OtpExpiredException for an expired OTP', function (): void {
    /** @var OtpService $otpService */
    $otpService = app(OtpService::class);

    $email = 'expired@example.com';

    AuthOtpCode::create([
        'email'      => $email,
        'type'       => 'email_verify',
        'token'      => '222222',
        'temp_token' => \Illuminate\Support\Str::uuid()->toString(),
        'expires_at' => now()->subMinutes(1), // expired
    ]);

    expect(fn () => $otpService->validateOtp($email, '222222', 'email_verify'))
        ->toThrow(OtpExpiredException::class);
});

it('marks previous OTPs as used when invalidatePrevious is called', function (): void {
    /** @var OtpService $otpService */
    $otpService = app(OtpService::class);

    $email = 'invalidate@example.com';

    $record1 = AuthOtpCode::create([
        'email'      => $email,
        'type'       => 'email_verify',
        'token'      => '333333',
        'temp_token' => \Illuminate\Support\Str::uuid()->toString(),
        'expires_at' => now()->addMinutes(10),
    ]);

    $record2 = AuthOtpCode::create([
        'email'      => $email,
        'type'       => 'email_verify',
        'token'      => '444444',
        'temp_token' => \Illuminate\Support\Str::uuid()->toString(),
        'expires_at' => now()->addMinutes(10),
    ]);

    expect($record1->used_at)->toBeNull();
    expect($record2->used_at)->toBeNull();

    $otpService->invalidatePrevious($email, 'email_verify');

    $record1->refresh();
    $record2->refresh();

    expect($record1->used_at)->not->toBeNull();
    expect($record2->used_at)->not->toBeNull();
});
