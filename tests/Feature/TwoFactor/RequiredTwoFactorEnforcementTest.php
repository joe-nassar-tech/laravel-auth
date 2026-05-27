<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    Notification::fake();
    \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'user', 'guard_name' => 'web']);
    config(['auth_system.two_factor.enabled' => true]);
});

it('blocks a protected package route when required 2FA is not enrolled', function (): void {
    config(['auth_system.two_factor.required' => true]);

    ['token' => $token] = $this->createAuthenticatedUser(['email' => 'req@example.com']);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/auth/sessions')
        ->assertStatus(403)
        ->assertJsonPath('data.must_enroll_2fa', true);
});

it('still allows the enrollment, me and logout endpoints so the user can enroll', function (): void {
    config(['auth_system.two_factor.required' => true]);

    ['token' => $token] = $this->createAuthenticatedUser(['email' => 'req2@example.com']);

    $this->withHeader('Authorization', "Bearer {$token}")->getJson('/auth/2fa/methods')->assertOk();
    $this->withHeader('Authorization', "Bearer {$token}")->getJson('/auth/me')->assertOk();
    $this->withHeader('Authorization', "Bearer {$token}")->postJson('/auth/2fa/enroll/totp/start')->assertOk();
});

it('allows protected routes once a verified 2FA method exists', function (): void {
    config(['auth_system.two_factor.required' => true]);

    ['user' => $user, 'token' => $token] = $this->createAuthenticatedUser(['email' => 'req3@example.com']);
    enrollTotp($user);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/auth/sessions')
        ->assertOk();
});

it('does not enforce when required is false (default, back-compat)', function (): void {
    config(['auth_system.two_factor.required' => false]);

    ['token' => $token] = $this->createAuthenticatedUser(['email' => 'req4@example.com']);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/auth/sessions')
        ->assertOk();
});

it('flags must_enroll_2fa in the login response when required and not enrolled', function (): void {
    config(['auth_system.two_factor.required' => true, 'auth_system.mode' => 'api']);

    $this->createUser(['email' => 'login2fa@example.com', 'password' => bcrypt('password')]);

    $this->postJson('/auth/login', ['email' => 'login2fa@example.com', 'password' => 'password'])
        ->assertOk()
        ->assertJsonPath('data.must_enroll_2fa', true);
});
