<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Joe404\LaravelAuth\Models\AuthOtpCode;
use Joe404\LaravelAuth\Notifications\CombinedOtpMagicLinkNotification;
use Joe404\LaravelAuth\Notifications\ExistingAccountNotification;
use Joe404\LaravelAuth\Notifications\OtpCodeNotification;
use Joe404\LaravelAuth\Tests\Fixtures\User;

beforeEach(function (): void {
    Notification::fake();
    Queue::fake();

    \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'user', 'guard_name' => 'web']);
});

/**
 * Helper: seed a known OTP for an email so we can submit the raw code.
 */
function seedOtp(string $email, string $rawOtp = '123456', string $type = 'email_verify'): AuthOtpCode
{
    return AuthOtpCode::create([
        'email'      => $email,
        'type'       => $type,
        'token'      => hash('sha256', $rawOtp),
        'temp_token' => Str::uuid()->toString(),
        'expires_at' => now()->addMinutes(10),
    ]);
}

it('initiates registration and returns temp_token (combined notification by default)', function (): void {
    config()->set('auth_system.verification.method', 'both');

    $response = $this->postJson('/auth/register', [
        'email' => 'newuser@example.com',
    ]);

    $response->assertStatus(201)
        ->assertJsonStructure(['success', 'message', 'data' => ['temp_token', 'method', 'expires_in']])
        ->assertJson(['success' => true]);

    Notification::assertSentOnDemand(CombinedOtpMagicLinkNotification::class);
});

it('initiate registration returns same response shape for an already-registered email (no enumeration oracle)', function (): void {
    User::create([
        'name'              => 'Existing User',
        'email'             => 'existing@example.com',
        'password'          => bcrypt('password'),
        'email_verified_at' => now(),
        'is_active'         => true,
    ]);

    $response = $this->postJson('/auth/register', ['email' => 'existing@example.com']);

    // Same 201 + temp_token shape — does not leak that the email is taken.
    $response->assertStatus(201)
        ->assertJsonStructure(['data' => ['temp_token', 'method', 'expires_in']])
        ->assertJson(['success' => true]);
});

it('verify-otp returns a completion_token and does NOT create a user yet', function (): void {
    config()->set('auth_system.verification.method', 'otp');

    $email = 'otp-verify@example.com';

    Cache::put("auth:pending:{$email}", ['extra' => []], now()->addMinutes(60));
    seedOtp($email, '654321');

    $response = $this->postJson('/auth/register/verify-otp', [
        'email' => $email,
        'otp'   => '654321',
    ]);

    $response->assertOk()
        ->assertJsonStructure(['data' => ['completion_token']])
        ->assertJson(['success' => true]);

    expect(User::where('email', $email)->exists())->toBeFalse();
});

it('completes registration on /register/complete with the completion_token + password', function (): void {
    config()->set('auth_system.verification.method', 'otp');

    $email = 'flow@example.com';

    Cache::put("auth:pending:{$email}", ['extra' => []], now()->addMinutes(60));
    seedOtp($email, '111111');

    $verify = $this->postJson('/auth/register/verify-otp', [
        'email' => $email,
        'otp'   => '111111',
    ])->assertOk();

    $completionToken = $verify->json('data.completion_token');

    $complete = $this->postJson('/auth/register/complete', [
        'completion_token'      => $completionToken,
        'password'              => 'Password123!',
        'password_confirmation' => 'Password123!',
    ]);

    $complete->assertStatus(201)
        ->assertJsonStructure(['data' => ['user', 'token', 'refresh_token']])
        ->assertJson(['success' => true]);

    expect(User::where('email', $email)->exists())->toBeTrue();
    expect($complete->json('data.token'))->not->toBeNull();
});

it('locks the OTP after AUTH_OTP_MAX_ATTEMPTS wrong submissions (brute-force defense)', function (): void {
    config()->set('auth_system.verification.method', 'otp');
    config()->set('auth_system.verification.otp_max_attempts', 3);

    $email = 'brute@example.com';
    Cache::put("auth:pending:{$email}", ['extra' => []], now()->addMinutes(60));
    seedOtp($email, '999999');

    foreach (['000000', '111111', '222222'] as $wrong) {
        $this->postJson('/auth/register/verify-otp', [
            'email' => $email,
            'otp'   => $wrong,
        ])->assertStatus(422);
    }

    // Even the correct OTP must now be rejected — the row was invalidated.
    $this->postJson('/auth/register/verify-otp', [
        'email' => $email,
        'otp'   => '999999',
    ])->assertStatus(422);

    expect(AuthOtpCode::where('email', $email)->whereNotNull('used_at')->exists())->toBeTrue();
});

it('rejects an expired OTP', function (): void {
    config()->set('auth_system.verification.method', 'otp');
    $email = 'expired@example.com';

    Cache::put("auth:pending:{$email}", ['extra' => []], now()->addMinutes(60));

    AuthOtpCode::create([
        'email'      => $email,
        'type'       => 'email_verify',
        'token'      => hash('sha256', '424242'),
        'temp_token' => Str::uuid()->toString(),
        'expires_at' => now()->subMinutes(5),
    ]);

    $this->postJson('/auth/register/verify-otp', [
        'email' => $email,
        'otp'   => '424242',
    ])->assertStatus(422);
});

it('cannot reuse an already-used OTP', function (): void {
    config()->set('auth_system.verification.method', 'otp');
    $email = 'reuse@example.com';
    Cache::put("auth:pending:{$email}", ['extra' => []], now()->addMinutes(60));

    AuthOtpCode::create([
        'email'      => $email,
        'type'       => 'email_verify',
        'token'      => hash('sha256', '555555'),
        'temp_token' => Str::uuid()->toString(),
        'expires_at' => now()->addMinutes(10),
        'used_at'    => now(),
    ]);

    $this->postJson('/auth/register/verify-otp', [
        'email' => $email,
        'otp'   => '555555',
    ])->assertStatus(422);
});

it('strips privileged fields (role, is_admin, etc.) from extra registration data', function (): void {
    config()->set('auth_system.verification.method', 'otp');
    config()->set('auth_system.registration.extra_fields_rules', [
        'is_admin' => 'nullable|boolean',
    ]);

    $email = 'privesc@example.com';

    $this->postJson('/auth/register', [
        'email'    => $email,
        'is_admin' => true,
    ])->assertStatus(201);

    seedOtp($email, '777777');

    $verify = $this->postJson('/auth/register/verify-otp', [
        'email' => $email,
        'otp'   => '777777',
    ])->assertOk();

    $this->postJson('/auth/register/complete', [
        'completion_token'      => $verify->json('data.completion_token'),
        'password'              => 'Password123!',
        'password_confirmation' => 'Password123!',
    ])->assertStatus(201);

    /** @var User $user */
    $user = User::where('email', $email)->first();

    // is_admin in fillable would have been cast to true if we hadn't stripped it.
    expect($user->getAttribute('is_admin'))->not->toBeTrue();
});

it('assigns the default role after successful registration', function (): void {
    config()->set('auth_system.verification.method', 'otp');
    $email = 'role@example.com';

    Cache::put("auth:pending:{$email}", ['extra' => []], now()->addMinutes(60));
    seedOtp($email, '101010');

    $verify = $this->postJson('/auth/register/verify-otp', [
        'email' => $email,
        'otp'   => '101010',
    ]);

    $this->postJson('/auth/register/complete', [
        'completion_token'      => $verify->json('data.completion_token'),
        'password'              => 'Password123!',
        'password_confirmation' => 'Password123!',
    ])->assertStatus(201);

    /** @var User $user */
    $user = User::where('email', $email)->first();
    expect($user->hasRole('user'))->toBeTrue();
});
