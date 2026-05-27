<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Crypt;
use Joe404\LaravelAuth\Models\AuthTwoFactorMethod;
use Joe404\LaravelAuth\Services\TotpService;
use PragmaRX\Google2FA\Google2FA;

beforeEach(function () {
    config([
        'auth_system.two_factor.enabled'  => true,
        'auth_system.trusted_devices.enabled' => true,
        'auth_system.trusted_devices.bypass_2fa_min_level' => 'medium',
    ]);
});

it('returns a challenge_token at login when a TOTP method is verified', function () {
    $user = $this->createUser(['email' => 'totp@example.com']);
    enrollTotp($user);

    $response = $this->postJson('/auth/login', [
        'email'    => 'totp@example.com',
        'password' => 'password',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.requires_2fa', true)
        ->assertJsonStructure(['data' => ['challenge_token', 'method', 'available_methods', 'expires_in']]);

    expect($response->json('data.method'))->toBe('totp');
});

it('completes the challenge with a valid TOTP code and issues a real token', function () {
    $user   = $this->createUser(['email' => 'totp2@example.com']);
    $secret = enrollTotp($user);

    $login = $this->postJson('/auth/login', [
        'email'    => 'totp2@example.com',
        'password' => 'password',
    ])->json('data');

    $code = (new Google2FA())->getCurrentOtp($secret);

    $verify = $this->postJson('/auth/2fa/challenge', [
        'challenge_token' => $login['challenge_token'],
        'code'            => $code,
        'method'          => 'totp',
    ]);

    $verify->assertOk()
        ->assertJsonPath('data.token', fn ($token) => is_string($token) && strlen($token) > 10);
});

it('rejects an invalid TOTP code', function () {
    $user = $this->createUser(['email' => 'totp3@example.com']);
    enrollTotp($user);

    $login = $this->postJson('/auth/login', [
        'email'    => 'totp3@example.com',
        'password' => 'password',
    ])->json('data');

    $this->postJson('/auth/2fa/challenge', [
        'challenge_token' => $login['challenge_token'],
        'code'            => '000000',
        'method'          => 'totp',
    ])->assertStatus(401)
      ->assertJsonPath('success', false);
});

// ---- helpers ----
// enrollTotp() is defined globally in tests/Pest.php so every test file
// (including parallel worker processes) can use it without relying on
// cross-file load order.
