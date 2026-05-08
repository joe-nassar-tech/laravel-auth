<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Joe404\LaravelAuth\Models\AuthOtpCode;
use Joe404\LaravelAuth\Notifications\OtpCodeNotification;
use Joe404\LaravelAuth\Services\AuthService;
use Joe404\LaravelAuth\Services\OtpService;
use Joe404\LaravelAuth\Tests\Fixtures\User;

beforeEach(function (): void {
    Notification::fake();
    Queue::fake();

    \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'user', 'guard_name' => 'web']);

    $this->user = User::create([
        'name'              => 'Reset User',
        'email'             => 'reset@example.com',
        'password'          => bcrypt('OldPassword1!'),
        'email_verified_at' => now(),
        'is_active'         => true,
    ]);
});

it('forgot password with valid email sends notification', function (): void {
    $response = $this->postJson('/auth/password/forgot', [
        'email' => 'reset@example.com',
    ]);

    $response->assertStatus(200)
        ->assertJson(['success' => true])
        ->assertJsonFragment(['message' => 'If that email is registered, you will receive reset instructions shortly.']);

    Notification::assertSentOnDemand(OtpCodeNotification::class);
});

it('forgot password with unknown email returns identical 200 response', function (): void {
    $response = $this->postJson('/auth/password/forgot', [
        'email' => 'nobody@example.com',
    ]);

    $response->assertStatus(200)
        ->assertJson(['success' => true])
        ->assertJsonFragment(['message' => 'If that email is registered, you will receive reset instructions shortly.']);
});

it('otp reset with valid otp updates password', function (): void {
    /** @var AuthService $authService */
    $authService = app(AuthService::class);
    $authService->forgotPassword('reset@example.com');

    $otpRecord = AuthOtpCode::where('email', 'reset@example.com')
        ->where('type', 'password_reset')
        ->whereNull('used_at')
        ->latest()
        ->first();

    expect($otpRecord)->not->toBeNull();

    $response = $this->postJson('/auth/password/reset/otp', [
        'email'                 => 'reset@example.com',
        'otp'                   => $otpRecord->token,
        'password'              => 'NewPassword1!',
        'password_confirmation' => 'NewPassword1!',
    ]);

    $response->assertStatus(200)
        ->assertJson(['success' => true]);

    $this->user->refresh();
    expect(\Illuminate\Support\Facades\Hash::check('NewPassword1!', $this->user->password))->toBeTrue();
});

it('otp reset with expired otp returns 422', function (): void {
    AuthOtpCode::create([
        'email'      => 'reset@example.com',
        'type'       => 'password_reset',
        'token'      => '111111',
        'temp_token' => Str::uuid()->toString(),
        'expires_at' => now()->subMinutes(5),
    ]);

    $response = $this->postJson('/auth/password/reset/otp', [
        'email'                 => 'reset@example.com',
        'otp'                   => '111111',
        'password'              => 'NewPassword1!',
        'password_confirmation' => 'NewPassword1!',
    ]);

    $response->assertStatus(422)
        ->assertJson(['success' => false]);
});

it('otp reset with already-used otp returns 422', function (): void {
    AuthOtpCode::create([
        'email'      => 'reset@example.com',
        'type'       => 'password_reset',
        'token'      => '222222',
        'temp_token' => Str::uuid()->toString(),
        'expires_at' => now()->addMinutes(10),
        'used_at'    => now(),
    ]);

    $response = $this->postJson('/auth/password/reset/otp', [
        'email'                 => 'reset@example.com',
        'otp'                   => '222222',
        'password'              => 'NewPassword1!',
        'password_confirmation' => 'NewPassword1!',
    ]);

    $response->assertStatus(422)
        ->assertJson(['success' => false]);
});

it('magic link redirect with valid signature returns reset_token', function (): void {
    /** @var OtpService $otpService */
    $otpService = app(OtpService::class);
    $otpService->sendMagicLink('reset@example.com', 'magic_link_reset');

    $magicRecord = AuthOtpCode::where('email', 'reset@example.com')
        ->where('type', 'magic_link_reset')
        ->whereNull('used_at')
        ->latest()
        ->first();

    expect($magicRecord)->not->toBeNull();

    $signedUrl = URL::temporarySignedRoute(
        'auth.password.reset.magic',
        now()->addMinutes(30),
        ['token' => $magicRecord->token],
    );

    $parsedUrl = parse_url($signedUrl);
    parse_str($parsedUrl['query'] ?? '', $queryParams);

    $response = $this->getJson(
        '/auth/password/reset/magic/' . $magicRecord->token
        . '?' . http_build_query($queryParams),
    );

    $response->assertStatus(200)
        ->assertJson(['success' => true])
        ->assertJsonStructure(['data' => ['reset_token']]);
});

it('magic link redirect with invalid signature returns 422', function (): void {
    $response = $this->getJson('/auth/password/reset/magic/fake-token');

    $response->assertStatus(422)
        ->assertJson(['success' => false]);
});

it('confirm with valid reset_token updates password', function (): void {
    $resetToken = Str::uuid()->toString();
    Cache::put("auth:reset_token:{$resetToken}", 'reset@example.com', now()->addMinutes(15));

    $response = $this->postJson('/auth/password/reset/confirm', [
        'reset_token'           => $resetToken,
        'password'              => 'NewPassword1!',
        'password_confirmation' => 'NewPassword1!',
    ]);

    $response->assertStatus(200)
        ->assertJson(['success' => true]);

    $this->user->refresh();
    expect(\Illuminate\Support\Facades\Hash::check('NewPassword1!', $this->user->password))->toBeTrue();
});

it('confirm with expired or invalid reset_token returns 422', function (): void {
    $response = $this->postJson('/auth/password/reset/confirm', [
        'reset_token'           => Str::uuid()->toString(),
        'password'              => 'NewPassword1!',
        'password_confirmation' => 'NewPassword1!',
    ]);

    $response->assertStatus(422)
        ->assertJson(['success' => false]);
});

it('otp reset revokes all user sessions and tokens', function (): void {
    // Give the user a Sanctum token
    $this->user->createToken('test-token');

    expect($this->user->tokens()->count())->toBe(1);

    /** @var AuthService $authService */
    $authService = app(AuthService::class);
    $authService->forgotPassword('reset@example.com');

    $otpRecord = AuthOtpCode::where('email', 'reset@example.com')
        ->where('type', 'password_reset')
        ->whereNull('used_at')
        ->latest()
        ->first();

    expect($otpRecord)->not->toBeNull();

    $this->postJson('/auth/password/reset/otp', [
        'email'                 => 'reset@example.com',
        'otp'                   => $otpRecord->token,
        'password'              => 'NewPassword1!',
        'password_confirmation' => 'NewPassword1!',
    ])->assertStatus(200);

    expect($this->user->tokens()->count())->toBe(0);
});
