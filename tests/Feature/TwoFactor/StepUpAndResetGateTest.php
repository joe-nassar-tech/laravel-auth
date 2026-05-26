<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Crypt;
use Joe404\LaravelAuth\Models\AuthOtpCode;
use Joe404\LaravelAuth\Models\AuthTwoFactorMethod;
use Joe404\LaravelAuth\Services\TotpService;
use Joe404\LaravelAuth\Tests\Fixtures\User;
use Illuminate\Support\Str;

beforeEach(function (): void {
    \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'user', 'guard_name' => 'web']);
    config(['auth_system.two_factor.enabled' => true]);
});

function suEnrollTotp(User $user): int
{
    return AuthTwoFactorMethod::create([
        'user_id'          => $user->getKey(),
        'type'             => 'totp',
        'secret_encrypted' => Crypt::encryptString((new TotpService())->generateSecret()),
        'is_default'       => true,
        'verified_at'      => now(),
    ])->id;
}

// ── #3 step-up on destructive 2FA actions ────────────────────────────────────

it('#3 blocks disabling a 2FA method without a step-up (password_confirm mode)', function (): void {
    config(['auth_system.two_factor.step_up_mode' => 'password_confirm']);

    ['user' => $user, 'token' => $token] = $this->createAuthenticatedUser([
        'email'    => 'stepup@example.com',
        'password' => bcrypt('Password123!'),
    ]);
    $methodId = suEnrollTotp($user);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson("/auth/2fa/methods/{$methodId}")
        ->assertStatus(403)
        ->assertJsonPath('data.step_up', 'password_confirm');
});

it('#3 allows disabling a 2FA method after a password confirm', function (): void {
    config(['auth_system.two_factor.step_up_mode' => 'password_confirm']);

    ['user' => $user, 'token' => $token] = $this->createAuthenticatedUser([
        'email'    => 'stepup2@example.com',
        'password' => bcrypt('Password123!'),
    ]);
    $methodId = suEnrollTotp($user);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/auth/password/confirm', ['password' => 'Password123!'])
        ->assertOk();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson("/auth/2fa/methods/{$methodId}")
        ->assertOk();

    expect(AuthTwoFactorMethod::find($methodId))->toBeNull();
});

it('#3 issues a 2FA challenge for step-up when step_up_mode=two_factor', function (): void {
    config(['auth_system.two_factor.step_up_mode' => 'two_factor']);

    ['user' => $user, 'token' => $token] = $this->createAuthenticatedUser(['email' => 'stepuptf@example.com']);
    $methodId = suEnrollTotp($user);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/auth/2fa/backup-codes/regenerate')
        ->assertStatus(403)
        ->assertJsonPath('data.step_up', 'two_factor')
        ->assertJsonStructure(['data' => ['challenge_token']]);
});

// ── #7 password reset auto-login goes through the 2FA gate ───────────────────

it('#7 password reset returns a 2FA challenge (no token) for a 2FA user', function (): void {
    config([
        'auth_system.two_factor.enabled' => true,
        'auth_system.password.min_length' => 8,
    ]);

    $user = $this->createUser(['email' => 'reset2fa@example.com']);
    suEnrollTotp($user);

    AuthOtpCode::create([
        'email'      => 'reset2fa@example.com',
        'type'       => 'password_reset',
        'token'      => authOtpHash('424242'),
        'temp_token' => Str::uuid()->toString(),
        'expires_at' => now()->addMinutes(10),
    ]);

    $resetToken = $this->postJson('/auth/password/reset/verify-otp', [
        'email' => 'reset2fa@example.com',
        'otp'   => '424242',
    ])->assertOk()->json('data.reset_token');

    $response = $this->postJson('/auth/password/reset/confirm', [
        'reset_token'           => $resetToken,
        'password'              => 'BrandNewPass1!',
        'password_confirmation' => 'BrandNewPass1!',
    ]);

    // Password was changed, but no token is issued until 2FA is completed.
    $response->assertOk()->assertJsonPath('data.requires_2fa', true);
    expect($response->json('data.token'))->toBeNull();
    expect(\Illuminate\Support\Facades\Hash::check('BrandNewPass1!', $user->fresh()->password))->toBeTrue();
});
