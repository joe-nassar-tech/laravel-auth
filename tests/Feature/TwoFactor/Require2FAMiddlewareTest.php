<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Route;
use Joe404\LaravelAuth\Models\AuthTwoFactorMethod;
use Joe404\LaravelAuth\Services\TotpService;

beforeEach(function () {
    config([
        'auth_system.two_factor.enabled' => true,
    ]);

    // Sample protected route guarded by the middleware.
    Route::middleware(['auth:sanctum', 'auth.2fa'])
        ->post('/test/sensitive', fn () => response()->json(['ok' => true]));
});

it('blocks (403) when user has no 2FA and behavior=block', function () {
    config(['auth_system.two_factor.middleware_behavior' => 'block']);

    ['token' => $token] = $this->createAuthenticatedUser(['email' => 'block@example.com']);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/test/sensitive')
        ->assertStatus(403)
        ->assertJsonPath('data.step_up', 'enroll_2fa');
});

it('asks for password confirm (403) when behavior=password_confirm and user has no 2FA', function () {
    config(['auth_system.two_factor.middleware_behavior' => 'password_confirm']);

    ['token' => $token] = $this->createAuthenticatedUser(['email' => 'pc@example.com']);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/test/sensitive')
        ->assertStatus(403)
        ->assertJsonPath('data.step_up', 'password_confirm');
});

it('lets the request through after password confirm', function () {
    config(['auth_system.two_factor.middleware_behavior' => 'password_confirm']);

    ['token' => $token] = $this->createAuthenticatedUser([
        'email'    => 'pc2@example.com',
        'password' => bcrypt('Password123!'),
    ]);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/auth/password/confirm', ['password' => 'Password123!'])
        ->assertOk();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/test/sensitive')
        ->assertOk()
        ->assertJsonPath('ok', true);
});

it('asks for force_enroll (403) when behavior=force_enroll', function () {
    config(['auth_system.two_factor.middleware_behavior' => 'force_enroll']);

    ['token' => $token] = $this->createAuthenticatedUser(['email' => 'fe@example.com']);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/test/sensitive')
        ->assertStatus(403)
        ->assertJsonPath('data.step_up', 'enroll_2fa');
});

it('issues a step-up challenge when user has 2FA enrolled', function () {
    ['user' => $user, 'token' => $token] = $this->createAuthenticatedUser(['email' => '2fa@example.com']);
    AuthTwoFactorMethod::create([
        'user_id'          => $user->getKey(),
        'type'             => 'totp',
        'secret_encrypted' => Crypt::encryptString((new TotpService())->generateSecret()),
        'is_default'       => true,
        'verified_at'      => now(),
    ]);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/test/sensitive')
        ->assertStatus(403)
        ->assertJsonPath('data.step_up', 'two_factor')
        ->assertJsonStructure(['data' => ['challenge_token', 'method', 'available_methods', 'expires_in']]);
});
