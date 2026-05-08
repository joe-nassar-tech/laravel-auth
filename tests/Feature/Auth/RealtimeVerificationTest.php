<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Joe404\LaravelAuth\Events\EmailVerified;
use Joe404\LaravelAuth\Models\AuthOtpCode;
use Joe404\LaravelAuth\Notifications\OtpCodeNotification;

beforeEach(function (): void {
    Notification::fake();
    Event::fake([EmailVerified::class]);
    \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'user', 'guard_name' => 'web']);
});

it('resend endpoint sends new otp when pending registration exists', function (): void {
    $email = 'resend-test@example.com';
    // Simulate a pending registration in cache
    Cache::put("auth:pending:{$email}", bcrypt('password123'), now()->addMinutes(60));

    $response = test()->postJson('/auth/email/resend-verification', ['email' => $email]);

    $response->assertStatus(200)->assertJson(['success' => true]);
    Notification::assertSentOnDemand(OtpCodeNotification::class);
});

it('resend endpoint returns same 200 when no pending registration exists', function (): void {
    // No cache entry for this email
    $response = test()->postJson('/auth/email/resend-verification', ['email' => 'nobody@example.com']);

    $response->assertStatus(200)->assertJson(['success' => true]);
    // No notification sent since no pending registration
    Notification::assertNothingSent();
});

it('resend invalidates previous otp and creates new one', function (): void {
    $email = 'resend-invalidate@example.com';
    Cache::put("auth:pending:{$email}", bcrypt('password'), now()->addMinutes(60));

    // First resend
    test()->postJson('/auth/email/resend-verification', ['email' => $email]);
    $firstOtp   = AuthOtpCode::where('email', $email)->where('type', 'email_verify')->whereNull('used_at')->latest()->first();
    $firstToken = $firstOtp?->token;

    // Second resend — should invalidate first
    test()->postJson('/auth/email/resend-verification', ['email' => $email]);

    // First OTP should now be used
    $firstOtp?->refresh();
    expect($firstOtp?->used_at)->not->toBeNull();

    // A new OTP should exist
    $newOtp = AuthOtpCode::where('email', $email)->where('type', 'email_verify')->whereNull('used_at')->latest()->first();
    expect($newOtp)->not->toBeNull();
    expect($newOtp->token)->not->toBe($firstToken);
});

it('email verified event is dispatched when otp verified', function (): void {
    // Use the full registration + verify flow
    $email = 'broadcast-test@example.com';
    test()->postJson('/auth/register', [
        'email'                 => $email,
        'password'              => 'password123',
        'password_confirmation' => 'password123',
    ]);

    $otpRecord = AuthOtpCode::where('email', $email)->where('type', 'email_verify')->latest()->first();

    test()->postJson('/auth/register/verify-otp', [
        'email' => $email,
        'otp'   => $otpRecord->token,
    ])->assertStatus(201);

    Event::assertDispatched(EmailVerified::class, function (EmailVerified $event) use ($email): bool {
        return $event->user->email === $email
            && $event->tempToken !== ''
            && $event->sanctumToken !== null;
    });
});

it('email verified event carries correct temp_token for channel subscription', function (): void {
    $email       = 'channel-test@example.com';
    $initResponse = test()->postJson('/auth/register', [
        'email'                 => $email,
        'password'              => 'password123',
        'password_confirmation' => 'password123',
    ])->assertStatus(201);

    $tempToken = $initResponse->json('data.temp_token');
    $otpRecord = AuthOtpCode::where('email', $email)->where('type', 'email_verify')->latest()->first();

    test()->postJson('/auth/register/verify-otp', [
        'email' => $email,
        'otp'   => $otpRecord->token,
    ])->assertStatus(201);

    Event::assertDispatched(EmailVerified::class, function (EmailVerified $event) use ($tempToken): bool {
        return $event->tempToken === $tempToken;
    });
});

it('email verified event is dispatched on magic link verification', function (): void {
    $email = 'magic-broadcast@example.com';

    // Config: magic_link only for this test
    config(['auth_system.verification.method' => 'magic_link']);

    test()->postJson('/auth/register', [
        'email'                 => $email,
        'password'              => 'password123',
        'password_confirmation' => 'password123',
    ])->assertStatus(201);

    $magicRecord = AuthOtpCode::where('email', $email)->where('type', 'magic_link_verify')->latest()->first();

    $signedUrl = \Illuminate\Support\Facades\URL::temporarySignedRoute(
        'auth.register.verify.magic',
        now()->addMinutes(30),
        ['token' => $magicRecord->token],
    );
    $parsedUrl = parse_url($signedUrl);
    parse_str($parsedUrl['query'] ?? '', $queryParams);

    test()->getJson('/auth/register/verify-magic/' . $magicRecord->token . '?' . http_build_query($queryParams))
        ->assertStatus(201);

    Event::assertDispatched(EmailVerified::class);
});

it('resend endpoint is rate limited', function (): void {
    $email = 'rate-limit@example.com';
    Cache::put("auth:pending:{$email}", bcrypt('password'), now()->addMinutes(60));

    // Lower the rate limit for this test
    config(['auth_system.rate_limits.otp_send' => '2:1']);

    test()->postJson('/auth/email/resend-verification', ['email' => $email])->assertStatus(200);
    test()->postJson('/auth/email/resend-verification', ['email' => $email])->assertStatus(200);
    // Third attempt should be rate limited
    test()->postJson('/auth/email/resend-verification', ['email' => $email])->assertStatus(429);
});
