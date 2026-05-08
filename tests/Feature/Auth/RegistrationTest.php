<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Joe404\LaravelAuth\Models\AuthOtpCode;
use Joe404\LaravelAuth\Notifications\OtpCodeNotification;
use Joe404\LaravelAuth\Tests\Fixtures\User;

beforeEach(function (): void {
    Notification::fake();
    Queue::fake();

    // Seed roles for registration tests
    \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'user', 'guard_name' => 'web']);
});

it('initiates registration and returns temp_token with OTP email sent', function (): void {
    $response = $this->postJson('/auth/register', [
        'email'                 => 'newuser@example.com',
        'password'              => 'password123',
        'password_confirmation' => 'password123',
    ]);

    $response->assertStatus(201)
        ->assertJsonStructure([
            'success',
            'message',
            'data' => ['temp_token', 'method', 'expires_in'],
        ])
        ->assertJson(['success' => true]);

    Notification::assertSentOnDemand(OtpCodeNotification::class);
});

it('returns 409 when trying to register a duplicate email', function (): void {
    User::create([
        'name'              => 'Existing User',
        'email'             => 'existing@example.com',
        'password'          => bcrypt('password'),
        'email_verified_at' => now(),
        'is_active'         => true,
    ]);

    $response = $this->postJson('/auth/register', [
        'email'                 => 'existing@example.com',
        'password'              => 'password123',
        'password_confirmation' => 'password123',
    ]);

    $response->assertStatus(409)
        ->assertJson(['success' => false]);
});

it('verifies OTP and completes registration returning token', function (): void {
    $email = 'otp-verify@example.com';

    // Initiate registration
    $initResponse = $this->postJson('/auth/register', [
        'email'                 => $email,
        'password'              => 'password123',
        'password_confirmation' => 'password123',
    ]);

    $initResponse->assertStatus(201);

    // Get the OTP code from the database
    $otpRecord = AuthOtpCode::where('email', $email)->where('type', 'email_verify')->latest()->first();
    expect($otpRecord)->not->toBeNull();

    // Verify with OTP
    $response = $this->postJson('/auth/register/verify-otp', [
        'email' => $email,
        'otp'   => $otpRecord->token,
    ]);

    $response->assertStatus(201)
        ->assertJsonStructure([
            'success',
            'message',
            'data' => ['user', 'token', 'temp_token'],
        ])
        ->assertJson(['success' => true]);

    expect(User::where('email', $email)->exists())->toBeTrue();
    expect($response->json('data.token'))->not->toBeNull();
});

it('verifies magic link and completes registration returning token', function (): void {
    $email = 'magic-verify@example.com';

    // Initiate registration
    $this->postJson('/auth/register', [
        'email'                 => $email,
        'password'              => 'password123',
        'password_confirmation' => 'password123',
    ])->assertStatus(201);

    // Get the magic link token from the database
    $magicRecord = AuthOtpCode::where('email', $email)->where('type', 'magic_link_verify')->latest()->first();
    expect($magicRecord)->not->toBeNull();

    // Build a valid signed URL for the token
    $signedUrl = \Illuminate\Support\Facades\URL::temporarySignedRoute(
        'auth.register.verify.magic',
        now()->addMinutes(30),
        ['token' => $magicRecord->token],
    );

    $parsedUrl = parse_url($signedUrl);
    parse_str($parsedUrl['query'] ?? '', $queryParams);

    $response = $this->getJson(
        '/auth/register/verify-magic/' . $magicRecord->token
        . '?' . http_build_query($queryParams),
    );

    $response->assertStatus(201)
        ->assertJson(['success' => true])
        ->assertJsonStructure(['data' => ['user', 'token', 'temp_token']]);

    expect(User::where('email', $email)->exists())->toBeTrue();
});

it('returns 422 for an expired OTP', function (): void {
    $email = 'expired-otp@example.com';

    Cache::put("auth:pending:{$email}", bcrypt('password123'), now()->addMinutes(60));

    AuthOtpCode::create([
        'email'      => $email,
        'type'       => 'email_verify',
        'token'      => '123456',
        'temp_token' => \Illuminate\Support\Str::uuid()->toString(),
        'expires_at' => now()->subMinutes(5), // already expired
    ]);

    $response = $this->postJson('/auth/register/verify-otp', [
        'email' => $email,
        'otp'   => '123456',
    ]);

    $response->assertStatus(422)
        ->assertJson(['success' => false, 'message' => 'The OTP code has expired.']);
});

it('cannot reuse an already-used OTP', function (): void {
    $email = 'reuse-otp@example.com';

    Cache::put("auth:pending:{$email}", bcrypt('password123'), now()->addMinutes(60));

    AuthOtpCode::create([
        'email'      => $email,
        'type'       => 'email_verify',
        'token'      => '654321',
        'temp_token' => \Illuminate\Support\Str::uuid()->toString(),
        'expires_at' => now()->addMinutes(10),
        'used_at'    => now(), // already used
    ]);

    $response = $this->postJson('/auth/register/verify-otp', [
        'email' => $email,
        'otp'   => '654321',
    ]);

    $response->assertStatus(422)
        ->assertJson(['success' => false, 'message' => 'The OTP code is invalid.']);
});

it('resending OTP invalidates the previous OTP', function (): void {
    $email = 'resend@example.com';

    // Initiate first OTP
    $this->postJson('/auth/register', [
        'email'                 => $email,
        'password'              => 'password123',
        'password_confirmation' => 'password123',
    ])->assertStatus(201);

    $firstOtp = AuthOtpCode::where('email', $email)
        ->where('type', 'email_verify')
        ->whereNull('used_at')
        ->latest()
        ->first();

    expect($firstOtp)->not->toBeNull();
    $firstToken = $firstOtp->token;

    // Re-initiate (same email — resend) — in a real resend endpoint this would call sendOtp directly
    // For now, test that initiating again invalidates the previous OTP
    // (registration re-initiate won't work since user doesn't exist yet)
    /** @var \Joe404\LaravelAuth\Services\OtpService $otpService */
    $otpService = app(\Joe404\LaravelAuth\Services\OtpService::class);
    $otpService->sendOtp($email, 'email_verify');

    $firstOtp->refresh();
    expect($firstOtp->used_at)->not->toBeNull();

    $newOtp = AuthOtpCode::where('email', $email)
        ->where('type', 'email_verify')
        ->whereNull('used_at')
        ->latest()
        ->first();

    expect($newOtp)->not->toBeNull();
    expect($newOtp->token)->not->toBe($firstToken);
});

it('assigns the user role after successful registration', function (): void {
    $email = 'role-check@example.com';

    $this->postJson('/auth/register', [
        'email'                 => $email,
        'password'              => 'password123',
        'password_confirmation' => 'password123',
    ])->assertStatus(201);

    $otpRecord = AuthOtpCode::where('email', $email)->where('type', 'email_verify')->latest()->first();

    $this->postJson('/auth/register/verify-otp', [
        'email' => $email,
        'otp'   => $otpRecord->token,
    ])->assertStatus(201);

    $user = User::where('email', $email)->first();
    expect($user)->not->toBeNull();
    expect($user->hasRole('user'))->toBeTrue();
});
