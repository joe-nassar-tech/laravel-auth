<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Crypt;
use Joe404\LaravelAuth\Models\AuthTrustedDevice;
use Joe404\LaravelAuth\Models\AuthTwoFactorMethod;
use Joe404\LaravelAuth\Services\TotpService;
use Joe404\LaravelAuth\Services\TrustedDeviceService;
use PragmaRX\Google2FA\Google2FA;

beforeEach(function () {
    config([
        'auth_system.two_factor.enabled' => true,
        'auth_system.trusted_devices.enabled' => true,
        // Lower threshold to make the test scenario reachable in one step.
        'auth_system.trusted_devices.bypass_2fa_min_level' => 'low',
        'auth_system.trusted_devices.thresholds_days' => [
            'low' => 0, 'medium' => 60, 'high' => 90,
        ],
        'auth_system.trusted_devices.token_header' => 'X-Trusted-Device-Token',
    ]);
});

it('does NOT bypass 2FA when only the fingerprint matches a trusted device', function () {
    $user   = $this->createUser(['email' => 'fpalone@example.com']);
    $secret = enrollUserTotp($user);

    // Pre-create a trusted device WITH a secret_hash + matching fingerprint.
    $fingerprint = str_repeat('a', 64);
    $plainSecret = bin2hex(random_bytes(32));

    AuthTrustedDevice::create([
        'user_id'          => $user->getKey(),
        'fingerprint_hash' => $fingerprint,
        'secret_hash'      => hash('sha256', $plainSecret),
        'level'            => 'high',
        'first_seen_at'    => now()->subDays(30),
        'last_seen_at'     => now(),
        'trusted_at'       => now()->subDays(30),
    ]);

    // Login presents the fingerprint but NOT the token → must NOT bypass.
    $response = $this->withHeader('X-Browser-Fingerprint', $fingerprint)
        ->postJson('/auth/login', [
            'email'    => 'fpalone@example.com',
            'password' => 'password',
        ]);

    $response->assertOk()
        ->assertJsonPath('data.requires_2fa', true);
});

it('bypasses 2FA when BOTH fingerprint and trusted_device_token are presented', function () {
    $user = $this->createUser(['email' => 'fptok@example.com']);
    enrollUserTotp($user);

    $fingerprint = str_repeat('b', 64);
    $plainSecret = bin2hex(random_bytes(32));

    AuthTrustedDevice::create([
        'user_id'          => $user->getKey(),
        'fingerprint_hash' => $fingerprint,
        'secret_hash'      => hash('sha256', $plainSecret),
        'level'            => 'high',
        'first_seen_at'    => now()->subDays(30),
        'last_seen_at'     => now(),
        'trusted_at'       => now()->subDays(30),
    ]);

    $response = $this->withHeader('X-Browser-Fingerprint', $fingerprint)
        ->withHeader('X-Trusted-Device-Token', $plainSecret)
        ->postJson('/auth/login', [
            'email'    => 'fptok@example.com',
            'password' => 'password',
        ]);

    $response->assertOk()
        ->assertJsonPath('data.requires_2fa', null)   // not set → no challenge
        ->assertJsonPath('data.token', fn ($t) => is_string($t) && strlen($t) > 10);
});

it('issues a fresh trusted_device_token when user opts into trust during 2FA challenge', function () {
    $user        = $this->createUser(['email' => 'optin@example.com']);
    $secret      = enrollUserTotp($user);
    $fingerprint = str_repeat('d', 64);

    $login = $this->withHeader('X-Browser-Fingerprint', $fingerprint)
        ->postJson('/auth/login', [
            'email'    => 'optin@example.com',
            'password' => 'password',
        ])->json('data');

    $code = (new Google2FA())->getCurrentOtp($secret);

    $verify = $this->withHeader('X-Browser-Fingerprint', $fingerprint)
        ->postJson('/auth/2fa/challenge', [
            'challenge_token' => $login['challenge_token'],
            'code'            => $code,
            'method'          => 'totp',
            'trust_device'    => true,
        ]);

    $verify->assertOk();
    $token = $verify->json('data.trusted_device_token');

    expect($token)->toBeString()->and(strlen($token))->toBe(64);

    // The stored row's secret_hash should match SHA-256 of the returned token.
    $device = AuthTrustedDevice::where('user_id', $user->getKey())->first();
    expect($device->secret_hash)->toBe(hash('sha256', $token));
});

it('does NOT issue or expose trusted_device_token when trust_device is omitted', function () {
    $user   = $this->createUser(['email' => 'notrust@example.com']);
    $secret = enrollUserTotp($user);

    $login = $this->postJson('/auth/login', [
        'email'    => 'notrust@example.com',
        'password' => 'password',
    ])->json('data');

    $code   = (new Google2FA())->getCurrentOtp($secret);
    $verify = $this->postJson('/auth/2fa/challenge', [
        'challenge_token' => $login['challenge_token'],
        'code'            => $code,
        'method'          => 'totp',
    ]);

    $verify->assertOk();
    expect($verify->json('data.trusted_device_token'))->toBeNull();
});

it('treats a token that does not match the stored hash as untrusted', function () {
    $user   = $this->createUser(['email' => 'wrong@example.com']);
    enrollUserTotp($user);

    $fingerprint = str_repeat('c', 64);

    AuthTrustedDevice::create([
        'user_id'          => $user->getKey(),
        'fingerprint_hash' => $fingerprint,
        'secret_hash'      => hash('sha256', bin2hex(random_bytes(32))),
        'level'            => 'high',
        'first_seen_at'    => now()->subDays(30),
        'last_seen_at'     => now(),
        'trusted_at'       => now()->subDays(30),
    ]);

    $response = $this->withHeader('X-Browser-Fingerprint', $fingerprint)
        ->withHeader('X-Trusted-Device-Token', 'wrong-token-value')
        ->postJson('/auth/login', [
            'email'    => 'wrong@example.com',
            'password' => 'password',
        ]);

    $response->assertOk()
        ->assertJsonPath('data.requires_2fa', true);
});

// ---- helpers ----

function enrollUserTotp($user): string
{
    $secret = (new TotpService())->generateSecret();

    AuthTwoFactorMethod::create([
        'user_id'          => $user->getKey(),
        'type'             => 'totp',
        'secret_encrypted' => Crypt::encryptString($secret),
        'is_default'       => true,
        'verified_at'      => now(),
    ]);

    return $secret;
}
