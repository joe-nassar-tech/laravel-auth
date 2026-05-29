<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

beforeEach(function (): void {
    \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'user', 'guard_name' => 'web']);
});

it('does not auto-login after reset when password_reset.auto_login is false', function (): void {
    config(['auth_system.password_reset.auto_login' => false]);

    $user  = $this->createUser(['email' => 'noauto@example.com', 'password' => bcrypt('oldpassword')]);
    $token = $user->createToken('existing')->plainTextToken; // a pre-existing session

    $resetToken = Str::uuid()->toString();
    Cache::put("auth:reset_token:{$resetToken}", 'noauto@example.com', now()->addMinutes(15));

    $resp = $this->postJson('/auth/password/reset/confirm', [
        'reset_token'           => $resetToken,
        'password'              => 'BrandNewPass1!',
        'password_confirmation' => 'BrandNewPass1!',
    ])->assertOk();

    $resp->assertJsonPath('data.auto_login', false);
    expect($resp->json('data.token'))->toBeNull();
    expect(Hash::check('BrandNewPass1!', $user->fresh()->password))->toBeTrue();

    // High-security posture: existing sessions are revoked so the reset forces
    // a fresh login everywhere.
    expect(PersonalAccessToken::findToken($token))->toBeNull();
});

it('auto-logs in after reset by default (auto_login true)', function (): void {
    config(['auth_system.password_reset.auto_login' => true]);

    $user = $this->createUser(['email' => 'auto@example.com', 'password' => bcrypt('oldpassword')]);

    $resetToken = Str::uuid()->toString();
    Cache::put("auth:reset_token:{$resetToken}", 'auto@example.com', now()->addMinutes(15));

    $resp = $this->postJson('/auth/password/reset/confirm', [
        'reset_token'           => $resetToken,
        'password'              => 'BrandNewPass1!',
        'password_confirmation' => 'BrandNewPass1!',
    ])->assertOk();

    // Test mode is 'api' → a real token is issued (user is logged in).
    expect($resp->json('data.token'))->not->toBeNull();
});
