<?php

declare(strict_types=1);

use Joe404\LaravelAuth\Models\AuthApiToken;

beforeEach(function (): void {
    config()->set('auth_system.api_tokens.enabled', true);

    foreach (['super-admin', 'admin', 'user'] as $role) {
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
    }
});

function revokeUserAndToken(): array
{
    $user = test()->createUser([
        'email'    => 'rv_' . uniqid() . '@example.com',
        'password' => bcrypt('Password123!'),
    ]);
    $user->assignRole('user');

    return [$user, $user->createToken('t')->plainTextToken];
}

it('require_step_up_for_revoke: blocks DELETE without a fresh step-up', function (): void {
    config([
        'auth_system.api_tokens.require_step_up_for_revoke' => true,
        'auth_system.two_factor.step_up_mode'               => 'password_confirm',
    ]);

    [$user, $bearer] = revokeUserAndToken();

    // A second token to be revoked (so we don't kill the bearer we're authenticating with).
    $issue = app(\Joe404\LaravelAuth\Services\ApiTokenService::class)->issue('victim', ['read'], null, $user);
    $tokenId = $issue['token']->id;

    test()->withToken($bearer)
        ->deleteJson("/auth/api-tokens/{$tokenId}")
        ->assertStatus(403)
        ->assertJsonPath('data.step_up', 'password_confirm');
});

it('require_step_up_for_revoke: allows DELETE after a password confirm', function (): void {
    config([
        'auth_system.api_tokens.require_step_up_for_revoke' => true,
        'auth_system.two_factor.step_up_mode'               => 'password_confirm',
    ]);

    [$user, $bearer] = revokeUserAndToken();
    $issue   = app(\Joe404\LaravelAuth\Services\ApiTokenService::class)->issue('victim', ['read'], null, $user);
    $tokenId = $issue['token']->id;

    test()->withToken($bearer)->postJson('/auth/password/confirm', ['password' => 'Password123!'])->assertOk();

    test()->withToken($bearer)->deleteJson("/auth/api-tokens/{$tokenId}")->assertOk();
});

it('require_step_up_for_revoke false (default) lets users revoke directly', function (): void {
    config(['auth_system.api_tokens.require_step_up_for_revoke' => false]);

    [$user, $bearer] = revokeUserAndToken();
    $issue   = app(\Joe404\LaravelAuth\Services\ApiTokenService::class)->issue('victim', ['read'], null, $user);
    $tokenId = $issue['token']->id;

    test()->withToken($bearer)->deleteJson("/auth/api-tokens/{$tokenId}")->assertOk();
});
