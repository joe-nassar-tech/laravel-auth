<?php

declare(strict_types=1);

use Joe404\LaravelAuth\Models\AuthTwoFactorChallenge;

beforeEach(function () {
    config([
        'auth_system.two_factor.enabled'  => true,
        'auth_system.trusted_devices.enabled' => true,
        'auth_system.trusted_devices.bypass_2fa_min_level' => 'medium',
        'auth_system.two_factor.challenge.max_attempts'   => 5,
        'auth_system.two_factor.challenge.burst_max_per_minute' => 50,
    ]);
});

it('reuses an existing unconsumed challenge instead of creating new rows', function () {
    $user = $this->createUser(['email' => 'reuse@example.com']);
    enrollTotp($user);

    $first  = $this->postJson('/auth/login', ['email' => 'reuse@example.com', 'password' => 'password']);
    $second = $this->postJson('/auth/login', ['email' => 'reuse@example.com', 'password' => 'password']);
    $third  = $this->postJson('/auth/login', ['email' => 'reuse@example.com', 'password' => 'password']);

    $tokens = collect([$first, $second, $third])->map(fn ($r) => $r->json('data.challenge_token'));

    expect($tokens->unique()->count())->toBe(1)
        ->and(AuthTwoFactorChallenge::where('user_id', $user->getKey())->count())->toBe(1);
});

it('invalidates the challenge after max_attempts wrong codes', function () {
    config(['auth_system.two_factor.challenge.max_attempts' => 3]);

    $user = $this->createUser(['email' => 'lock@example.com']);
    enrollTotp($user);

    $login = $this->postJson('/auth/login', ['email' => 'lock@example.com', 'password' => 'password'])->json('data');

    // Burn through max_attempts with wrong codes.
    for ($i = 1; $i <= 3; $i++) {
        $this->postJson('/auth/2fa/challenge', [
            'challenge_token' => $login['challenge_token'],
            'code'            => '000000',
            'method'          => 'totp',
        ])->assertStatus(401);
    }

    // Next attempt — even with the right code — must be rejected because the
    // challenge was invalidated at the threshold.
    $response = $this->postJson('/auth/2fa/challenge', [
        'challenge_token' => $login['challenge_token'],
        'code'            => '000000',
        'method'          => 'totp',
    ]);

    $response->assertStatus(401)
        ->assertJsonPath('message', 'Too many failed attempts. Please log in again.');

    // Challenge row should now be consumed.
    $challenge = AuthTwoFactorChallenge::where('challenge_token', $login['challenge_token'])->first();
    expect($challenge->consumed_at)->not->toBeNull();
});

it('still fires UserLoggedIn at credential success when 2FA is required', function () {
    \Illuminate\Support\Facades\Event::fake([\Joe404\LaravelAuth\Events\UserLoggedIn::class]);

    $user = $this->createUser(['email' => 'evt@example.com']);
    enrollTotp($user);

    $this->postJson('/auth/login', ['email' => 'evt@example.com', 'password' => 'password'])
        ->assertJsonPath('data.requires_2fa', true);

    \Illuminate\Support\Facades\Event::assertDispatched(\Joe404\LaravelAuth\Events\UserLoggedIn::class);
});

it('fires UserLoggedIn exactly once across the full 2FA login (no double-dispatch)', function () {
    \Illuminate\Support\Facades\Event::fake([
        \Joe404\LaravelAuth\Events\UserLoggedIn::class,
        \Joe404\LaravelAuth\Events\TwoFactorVerified::class,
    ]);

    $user   = $this->createUser(['email' => 'once@example.com']);
    $secret = enrollTotp($user);

    $login = $this->postJson('/auth/login', ['email' => 'once@example.com', 'password' => 'password'])->json('data');

    $code = (new \PragmaRX\Google2FA\Google2FA())->getCurrentOtp($secret);
    $this->postJson('/auth/2fa/challenge', [
        'challenge_token' => $login['challenge_token'],
        'code'            => $code,
        'method'          => 'totp',
    ])->assertOk();

    // Credential success fired it once; challenge completion must NOT fire it again.
    \Illuminate\Support\Facades\Event::assertDispatchedTimes(\Joe404\LaravelAuth\Events\UserLoggedIn::class, 1);
    \Illuminate\Support\Facades\Event::assertDispatched(\Joe404\LaravelAuth\Events\TwoFactorVerified::class);
});

it('dispatches the new-device suspicious-login alert when a 2FA login completes from an unseen device', function () {
    config(['auth_system.security.notify_new_device_login' => true]);

    \Illuminate\Support\Facades\Event::fake([\Joe404\LaravelAuth\Events\SuspiciousLoginDetected::class]);

    $user   = $this->createUser(['email' => 'newdev@example.com']);
    $secret = enrollTotp($user);

    $login = $this->withHeader('X-Client-Type', 'mobile')
        ->withHeader('X-Device-Info', 'model=Pixel8;os=Android 14;id=brand-new-device-001')
        ->postJson('/auth/login', ['email' => 'newdev@example.com', 'password' => 'password'])->json('data');

    $code = (new \PragmaRX\Google2FA\Google2FA())->getCurrentOtp($secret);
    $this->withHeader('X-Client-Type', 'mobile')
        ->withHeader('X-Device-Info', 'model=Pixel8;os=Android 14;id=brand-new-device-001')
        ->postJson('/auth/2fa/challenge', [
            'challenge_token' => $login['challenge_token'],
            'code'            => $code,
            'method'          => 'totp',
        ])->assertOk();

    \Illuminate\Support\Facades\Event::assertDispatched(\Joe404\LaravelAuth\Events\SuspiciousLoginDetected::class);
});

// helper enrollTotp() is defined in TotpChallengeFlowTest.php (loaded first by Pest).
