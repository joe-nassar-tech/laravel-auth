<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Joe404\LaravelAuth\Models\AuthOtpCode;
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

function seedResetOtp(string $email, string $rawOtp = '424242'): AuthOtpCode
{
    return AuthOtpCode::create([
        'email'      => $email,
        'type'       => 'password_reset',
        'token'      => hash('sha256', $rawOtp),
        'temp_token' => Str::uuid()->toString(),
        'expires_at' => now()->addMinutes(10),
    ]);
}

it('forgot password returns 200 envelope for known emails', function (): void {
    $this->postJson('/auth/password/forgot', ['email' => 'reset@example.com'])
        ->assertStatus(200)
        ->assertJson(['success' => true]);
});

it('forgot password returns identical 200 envelope for unknown emails (no enumeration)', function (): void {
    $this->postJson('/auth/password/forgot', ['email' => 'nobody@example.com'])
        ->assertStatus(200)
        ->assertJson(['success' => true])
        ->assertJsonFragment([
            'message' => 'If that email is registered, you will receive reset instructions shortly.',
        ]);
});

it('otp reset with the correct raw OTP updates the password', function (): void {
    seedResetOtp('reset@example.com', '424242');

    $this->postJson('/auth/password/reset/otp', [
        'email'                 => 'reset@example.com',
        'otp'                   => '424242',
        'password'              => 'NewPassword1!',
        'password_confirmation' => 'NewPassword1!',
    ])->assertStatus(200);

    $this->user->refresh();
    expect(Hash::check('NewPassword1!', $this->user->password))->toBeTrue();
});

it('otp reset with expired OTP returns 422', function (): void {
    AuthOtpCode::create([
        'email'      => 'reset@example.com',
        'type'       => 'password_reset',
        'token'      => hash('sha256', '111111'),
        'temp_token' => Str::uuid()->toString(),
        'expires_at' => now()->subMinutes(5),
    ]);

    $this->postJson('/auth/password/reset/otp', [
        'email'                 => 'reset@example.com',
        'otp'                   => '111111',
        'password'              => 'NewPassword1!',
        'password_confirmation' => 'NewPassword1!',
    ])->assertStatus(422);
});

it('otp reset with already-used OTP returns 422', function (): void {
    AuthOtpCode::create([
        'email'      => 'reset@example.com',
        'type'       => 'password_reset',
        'token'      => hash('sha256', '222222'),
        'temp_token' => Str::uuid()->toString(),
        'expires_at' => now()->addMinutes(10),
        'used_at'    => now(),
    ]);

    $this->postJson('/auth/password/reset/otp', [
        'email'                 => 'reset@example.com',
        'otp'                   => '222222',
        'password'              => 'NewPassword1!',
        'password_confirmation' => 'NewPassword1!',
    ])->assertStatus(422);
});

it('magic link redirect with a valid signature returns a reset_token', function (): void {
    $rawUuid = Str::uuid()->toString();

    AuthOtpCode::create([
        'email'      => 'reset@example.com',
        'type'       => 'magic_link_reset',
        'token'      => hash('sha256', $rawUuid),
        'temp_token' => Str::uuid()->toString(),
        'expires_at' => now()->addMinutes(30),
    ]);

    $signedUrl = URL::temporarySignedRoute(
        'auth.password.reset.magic',
        now()->addMinutes(30),
        ['token' => $rawUuid],
    );

    $query = parse_url($signedUrl, PHP_URL_QUERY);

    $this->getJson('/auth/password/reset/magic/' . $rawUuid . '?' . $query)
        ->assertStatus(200)
        ->assertJsonStructure(['data' => ['reset_token']]);
});

it('magic link redirect with invalid signature returns 422', function (): void {
    $this->getJson('/auth/password/reset/magic/fake-token')
        ->assertStatus(422);
});

it('reset/confirm with a valid reset_token updates the password', function (): void {
    $resetToken = Str::uuid()->toString();
    Cache::put("auth:reset_token:{$resetToken}", 'reset@example.com', now()->addMinutes(15));

    $this->postJson('/auth/password/reset/confirm', [
        'reset_token'           => $resetToken,
        'password'              => 'NewPassword1!',
        'password_confirmation' => 'NewPassword1!',
    ])->assertStatus(200);

    $this->user->refresh();
    expect(Hash::check('NewPassword1!', $this->user->password))->toBeTrue();
});

it('reset/confirm with an unknown reset_token returns 422', function (): void {
    $this->postJson('/auth/password/reset/confirm', [
        'reset_token'           => Str::uuid()->toString(),
        'password'              => 'NewPassword1!',
        'password_confirmation' => 'NewPassword1!',
    ])->assertStatus(422);
});

it('successful otp reset revokes all of the user other Sanctum tokens', function (): void {
    $this->user->createToken('a');
    $this->user->createToken('b');

    expect($this->user->tokens()->count())->toBe(2);

    seedResetOtp('reset@example.com', '999999');

    $this->postJson('/auth/password/reset/otp', [
        'email'                 => 'reset@example.com',
        'otp'                   => '999999',
        'password'              => 'NewPassword1!',
        'password_confirmation' => 'NewPassword1!',
    ])->assertStatus(200);

    expect($this->user->fresh()->tokens()->count())->toBe(0);
});
