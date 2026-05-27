<?php

declare(strict_types=1);

use Joe404\LaravelAuth\Models\AuthTwoFactorMethod;
use PragmaRX\Google2FA\Google2FA;

beforeEach(function (): void {
    \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'user', 'guard_name' => 'web']);
    config(['auth_system.two_factor.enabled' => true]);
});

it('rejects replay of an already-used TOTP code within its validity window', function (): void {
    $user   = $this->createUser(['email' => 'replay@example.com', 'password' => bcrypt('password')]);
    $secret = enrollTotp($user);

    $code = (new Google2FA())->getCurrentOtp($secret);

    // First login → challenge → the code is accepted and its time-step recorded.
    $challenge1 = $this->postJson('/auth/login', [
        'email' => 'replay@example.com', 'password' => 'password',
    ])->json('data.challenge_token');

    $this->postJson('/auth/2fa/challenge', [
        'challenge_token' => $challenge1,
        'code'            => $code,
        'method'          => 'totp',
    ])->assertOk();

    expect(
        AuthTwoFactorMethod::where('user_id', $user->getKey())->first()->last_totp_timestep
    )->not->toBeNull();

    // Second login with the SAME code → rejected as replay.
    $challenge2 = $this->postJson('/auth/login', [
        'email' => 'replay@example.com', 'password' => 'password',
    ])->json('data.challenge_token');

    $this->postJson('/auth/2fa/challenge', [
        'challenge_token' => $challenge2,
        'code'            => $code,
        'method'          => 'totp',
    ])->assertStatus(401)->assertJsonPath('success', false);
});
