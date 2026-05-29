<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Hash;
use Joe404\LaravelAuth\Exceptions\PhoneVerificationException;
use Joe404\LaravelAuth\Models\AuthPhoneOtpCode;
use Joe404\LaravelAuth\Services\PhoneVerificationService;

beforeEach(function (): void {
    \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'user', 'guard_name' => 'web']);
});

it('consumes a phone OTP exactly once (single-use)', function (): void {
    $user = $this->createUser(['email' => 'p-once@example.com']);

    AuthPhoneOtpCode::create([
        'user_id'    => $user->getKey(),
        'phone'      => '+1234567890',
        'purpose'    => AuthPhoneOtpCode::PURPOSE_PHONE_VERIFY,
        'code_hash'  => Hash::make('123456'),
        'channel'    => 'sms',
        'attempts'   => 0,
        'expires_at' => now()->addMinutes(10),
        'created_at' => now(),
    ]);

    $svc = app(PhoneVerificationService::class);

    // First consume succeeds.
    $svc->verifyCode('+1234567890', '123456', AuthPhoneOtpCode::PURPOSE_PHONE_VERIFY);

    // Second call with the same code is rejected.
    expect(fn () => $svc->verifyCode('+1234567890', '123456', AuthPhoneOtpCode::PURPOSE_PHONE_VERIFY))
        ->toThrow(PhoneVerificationException::class);
});

it('the conditional consume is atomic — only one update wins the row', function (): void {
    $row = AuthPhoneOtpCode::create([
        'phone'      => '+1234567000',
        'purpose'    => AuthPhoneOtpCode::PURPOSE_PHONE_VERIFY,
        'code_hash'  => Hash::make('999000'),
        'channel'    => 'sms',
        'attempts'   => 0,
        'expires_at' => now()->addMinutes(10),
        'created_at' => now(),
    ]);

    $first  = AuthPhoneOtpCode::where('id', $row->getKey())->whereNull('consumed_at')->update(['consumed_at' => now()]);
    $second = AuthPhoneOtpCode::where('id', $row->getKey())->whereNull('consumed_at')->update(['consumed_at' => now()]);

    expect($first)->toBe(1);
    expect($second)->toBe(0); // the loser gets 0 → verifyCode throws
});
