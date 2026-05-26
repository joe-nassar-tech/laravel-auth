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

it('two-step otp reset (verify-otp → confirm) updates the password', function (): void {
    seedResetOtp('reset@example.com', '424242');

    // Step 1 — exchange the OTP for a reset_token.
    $resetToken = $this->postJson('/auth/password/reset/verify-otp', [
        'email' => 'reset@example.com',
        'otp'   => '424242',
    ])->assertOk()->json('data.reset_token');

    expect($resetToken)->toBeString();

    // Step 2 — set the new password with the reset_token.
    $this->postJson('/auth/password/reset/confirm', [
        'reset_token'           => $resetToken,
        'password'              => 'NewPassword1!',
        'password_confirmation' => 'NewPassword1!',
    ])->assertStatus(200);

    $this->user->refresh();
    expect(Hash::check('NewPassword1!', $this->user->password))->toBeTrue();
});

it('verify-otp with an expired OTP returns 422', function (): void {
    AuthOtpCode::create([
        'email'      => 'reset@example.com',
        'type'       => 'password_reset',
        'token'      => hash('sha256', '111111'),
        'temp_token' => Str::uuid()->toString(),
        'expires_at' => now()->subMinutes(5),
    ]);

    $this->postJson('/auth/password/reset/verify-otp', [
        'email' => 'reset@example.com',
        'otp'   => '111111',
    ])->assertStatus(422);
});

it('verify-otp with an already-used OTP returns 422', function (): void {
    AuthOtpCode::create([
        'email'      => 'reset@example.com',
        'type'       => 'password_reset',
        'token'      => hash('sha256', '222222'),
        'temp_token' => Str::uuid()->toString(),
        'expires_at' => now()->addMinutes(10),
        'used_at'    => now(),
    ]);

    $this->postJson('/auth/password/reset/verify-otp', [
        'email' => 'reset@example.com',
        'otp'   => '222222',
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

it('reset confirm with logout_all revokes the user prior Sanctum tokens', function (): void {
    $t1 = $this->user->createToken('a')->accessToken->id;
    $t2 = $this->user->createToken('b')->accessToken->id;

    expect($this->user->tokens()->count())->toBe(2);

    seedResetOtp('reset@example.com', '999999');

    $resetToken = $this->postJson('/auth/password/reset/verify-otp', [
        'email' => 'reset@example.com',
        'otp'   => '999999',
    ])->assertOk()->json('data.reset_token');

    $this->postJson('/auth/password/reset/confirm', [
        'reset_token'           => $resetToken,
        'password'              => 'NewPassword1!',
        'password_confirmation' => 'NewPassword1!',
        'logout_all'            => true,
    ])->assertStatus(200);

    // Both prior tokens are revoked. (A fresh token is issued by the
    // auto-login that follows a successful reset, so the user is not at 0.)
    expect(\Laravel\Sanctum\PersonalAccessToken::whereIn('id', [$t1, $t2])->count())->toBe(0);
});
