<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Joe404\LaravelAuth\Models\AuthOtpCode;
use Joe404\LaravelAuth\Models\AuthTwoFactorMethod;

beforeEach(function (): void {
    Notification::fake();
    \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'user', 'guard_name' => 'web']);
    config(['auth_system.two_factor.enabled' => true]);
});

/**
 * Regression for the v2.7 fix. auth_otp_codes.type shipped as an enum that
 * only allowed the four email-verify / password-reset purposes, so writing
 * 'two_factor_email_enroll' threw on strict MySQL / PostgreSQL / SQLite and
 * email-2FA enrollment 500'd. After widening the column to a string the start
 * endpoint must succeed and the row must persist. (assertOk() is itself the
 * regression: pre-fix this route errored.)
 */
it('persists a two_factor_email_enroll OTP when email enrollment starts', function (): void {
    ['user' => $user, 'token' => $token] = $this->createAuthenticatedUser([
        'email' => 'mail2fa@example.com',
    ]);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/auth/2fa/enroll/email/start')
        ->assertOk();

    expect(
        AuthOtpCode::where('email', 'mail2fa@example.com')
            ->where('type', 'two_factor_email_enroll')
            ->exists()
    )->toBeTrue();
});

it('completes email 2FA enrollment with the emailed code', function (): void {
    ['user' => $user, 'token' => $token] = $this->createAuthenticatedUser([
        'email' => 'mail2fa2@example.com',
    ]);

    // Start enrollment — creates the (unverified) method row plus a random
    // code we cannot read in a test.
    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/auth/2fa/enroll/email/start')
        ->assertOk();

    // Seed a known code as the latest unused enroll OTP so we can submit it.
    AuthOtpCode::create([
        'email'      => 'mail2fa2@example.com',
        'type'       => 'two_factor_email_enroll',
        'token'      => authOtpHash('246802'),
        'temp_token' => Str::uuid()->toString(),
        'expires_at' => now()->addMinutes(10),
    ]);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/auth/2fa/enroll/email/verify', ['code' => '246802'])
        ->assertOk();

    expect(
        AuthTwoFactorMethod::where('user_id', $user->getKey())
            ->where('type', 'email')
            ->whereNotNull('verified_at')
            ->exists()
    )->toBeTrue();
});

it('challenges with the email method at login and verifies the emailed code', function (): void {
    $user = $this->createUser([
        'email'    => 'mail2fa3@example.com',
        'password' => bcrypt('password'),
    ]);

    // Pre-enrolled, verified email 2FA method.
    AuthTwoFactorMethod::create([
        'user_id'     => $user->getKey(),
        'type'        => 'email',
        'is_default'  => true,
        'verified_at' => now(),
    ]);

    $login = $this->postJson('/auth/login', [
        'email'    => 'mail2fa3@example.com',
        'password' => 'password',
    ])->assertOk();

    $login->assertJsonPath('data.requires_2fa', true)
        ->assertJsonPath('data.method', 'email');

    $challengeToken = $login->json('data.challenge_token');

    // Login issued a random two_factor_email code; seed a known one as the
    // latest unused row so the challenge can be completed deterministically.
    AuthOtpCode::create([
        'email'      => 'mail2fa3@example.com',
        'type'       => 'two_factor_email',
        'token'      => authOtpHash('135790'),
        'temp_token' => Str::uuid()->toString(),
        'expires_at' => now()->addMinutes(10),
    ]);

    $response = $this->postJson('/auth/2fa/challenge', [
        'challenge_token' => $challengeToken,
        'code'            => '135790',
        'method'          => 'email',
    ])->assertOk();

    expect($response->json('data.token'))->not->toBeNull();
});
