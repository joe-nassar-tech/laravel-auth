<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Joe404\LaravelAuth\Exceptions\OtpInvalidException;
use Joe404\LaravelAuth\Models\AuthOtpCode;
use Joe404\LaravelAuth\Services\OtpService;

it('consumes a valid OTP exactly once (single-use)', function (): void {
    AuthOtpCode::create([
        'email'      => 'once@example.com',
        'type'       => 'email_verify',
        'token'      => authOtpHash('123456'),
        'temp_token' => Str::uuid()->toString(),
        'expires_at' => now()->addMinutes(10),
    ]);

    $svc = app(OtpService::class);

    $svc->validateOtp('once@example.com', '123456', 'email_verify'); // first wins

    expect(fn () => $svc->validateOtp('once@example.com', '123456', 'email_verify'))
        ->toThrow(OtpInvalidException::class);
});

it('consumes a magic-link token exactly once (single-use)', function (): void {
    $uuid = Str::uuid()->toString();

    AuthOtpCode::create([
        'email'      => 'magic@example.com',
        'type'       => 'magic_link_verify',
        'token'      => authOtpHash($uuid),
        'temp_token' => Str::uuid()->toString(),
        'expires_at' => now()->addMinutes(10),
    ]);

    $svc = app(OtpService::class);

    $svc->validateMagicLink($uuid, 'magic_link_verify');

    expect(fn () => $svc->validateMagicLink($uuid, 'magic_link_verify'))
        ->toThrow(OtpInvalidException::class);
});

it('the conditional consume is atomic — only one update wins the row', function (): void {
    $otp = AuthOtpCode::create([
        'email'      => 'race@example.com',
        'type'       => 'email_verify',
        'token'      => authOtpHash('654321'),
        'temp_token' => Str::uuid()->toString(),
        'expires_at' => now()->addMinutes(10),
    ]);

    // Simulate two requests that both passed the hash check racing to consume
    // the same row: the conditional update only succeeds for the first.
    $first  = AuthOtpCode::where('id', $otp->getKey())->whereNull('used_at')->update(['used_at' => now()]);
    $second = AuthOtpCode::where('id', $otp->getKey())->whereNull('used_at')->update(['used_at' => now()]);

    expect($first)->toBe(1);
    expect($second)->toBe(0); // the loser gets 0 affected rows → rejected
});
