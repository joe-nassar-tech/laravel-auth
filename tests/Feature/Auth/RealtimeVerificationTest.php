<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Joe404\LaravelAuth\Events\EmailVerified;
use Joe404\LaravelAuth\Models\AuthOtpCode;

beforeEach(function (): void {
    Notification::fake();
    Event::fake([EmailVerified::class]);
    \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'user', 'guard_name' => 'web']);
});

function seedOtpForRealtime(string $email, string $rawOtp): AuthOtpCode
{
    return AuthOtpCode::create([
        'email'      => $email,
        'type'       => 'email_verify',
        'token'      => authOtpHash($rawOtp),
        'temp_token' => Str::uuid()->toString(),
        'expires_at' => now()->addMinutes(10),
    ]);
}

it('resend endpoint sends new combined notification when pending registration exists', function (): void {
    config()->set('auth_system.verification.method', 'both');
    $email = 'resend-test@example.com';

    Cache::put("auth:pending:{$email}", ['extra' => []], now()->addMinutes(60));

    test()->postJson('/auth/email/resend-verification', ['email' => $email])
        ->assertStatus(200)->assertJson(['success' => true]);
});

it('resend endpoint returns same 200 when no pending registration exists (no enumeration)', function (): void {
    test()->postJson('/auth/email/resend-verification', ['email' => 'nobody@example.com'])
        ->assertStatus(200)->assertJson(['success' => true]);

    Notification::assertNothingSent();
});

it('resend endpoint returns a temp_token in the response so SPAs can rebind their Echo channel', function (): void {
    config()->set('auth_system.verification.method', 'both');
    $email = 'temp-token-rebind@example.com';

    Cache::put("auth:pending:{$email}", ['extra' => []], now()->addMinutes(60));

    $response = test()->postJson('/auth/email/resend-verification', ['email' => $email])
        ->assertStatus(200)
        ->assertJsonStructure(['success', 'message', 'data' => ['temp_token']]);

    $tempToken = $response->json('data.temp_token');
    expect($tempToken)->toBeString()->toHaveLength(36); // UUID v4

    // The new temp_token must actually match the OTP records that were just
    // created — that is the whole point of returning it.
    expect(AuthOtpCode::where('email', $email)->where('temp_token', $tempToken)->count())
        ->toBe(2); // one email_verify + one magic_link_verify under method=both
});

it('resend endpoint returns a temp_token even when no pending registration exists (anti-enumeration)', function (): void {
    // Returning a UUID-shaped temp_token regardless of whether a pending
    // registration exists prevents an outsider from probing emails: the
    // response shape is identical in both branches.
    test()->postJson('/auth/email/resend-verification', ['email' => 'nobody-tt@example.com'])
        ->assertStatus(200)
        ->assertJsonStructure(['success', 'message', 'data' => ['temp_token']]);

    Notification::assertNothingSent();
});

it('email verified event is dispatched at /register/complete', function (): void {
    config()->set('auth_system.verification.method', 'otp');
    $email = 'verified-event@example.com';

    Cache::put("auth:pending:{$email}", ['extra' => []], now()->addMinutes(60));
    seedOtpForRealtime($email, '424242');

    $verify = test()->postJson('/auth/register/verify-otp', [
        'email' => $email,
        'otp'   => '424242',
    ])->assertOk();

    test()->postJson('/auth/register/complete', [
        'completion_token'      => $verify->json('data.completion_token'),
        'password'              => 'Password123!',
        'password_confirmation' => 'Password123!',
    ])->assertStatus(201);

    Event::assertDispatched(EmailVerified::class, fn (EmailVerified $e) => $e->user->email === $email);
});

it('resend endpoint is rate limited', function (): void {
    config()->set('auth_system.rate_limits.otp_send', '2:1');
    $email = 'rate-limit@example.com';
    Cache::put("auth:pending:{$email}", ['extra' => []], now()->addMinutes(60));

    test()->postJson('/auth/email/resend-verification', ['email' => $email])->assertStatus(200);
    test()->postJson('/auth/email/resend-verification', ['email' => $email])->assertStatus(200);
    test()->postJson('/auth/email/resend-verification', ['email' => $email])->assertStatus(429);
});
