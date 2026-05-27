<?php

declare(strict_types=1);

beforeEach(function (): void {
    config()->set('auth_system.api_tokens.enabled', true);

    foreach (['super-admin', 'admin', 'user'] as $role) {
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
    }
});

function stepUpUser(): array
{
    $user = test()->createUser([
        'email'    => 'st_' . uniqid() . '@example.com',
        'password' => bcrypt('Password123!'),
    ]);
    $user->assignRole('user');

    return [$user, $user->createToken('t')->plainTextToken];
}

it('require_step_up: blocks token creation without a fresh step-up', function (): void {
    config([
        'auth_system.api_tokens.require_step_up' => true,
        'auth_system.two_factor.step_up_mode'    => 'password_confirm',
    ]);

    [, $token] = stepUpUser();

    $this->withToken($token)
        ->postJson('/auth/api-tokens', ['name' => 't'])
        ->assertStatus(403)
        ->assertJsonPath('data.step_up', 'password_confirm');
});

it('require_step_up: allows token creation after a password confirm', function (): void {
    config([
        'auth_system.api_tokens.require_step_up' => true,
        'auth_system.two_factor.step_up_mode'    => 'password_confirm',
    ]);

    [, $token] = stepUpUser();

    $this->withToken($token)->postJson('/auth/password/confirm', ['password' => 'Password123!'])->assertOk();
    $this->withToken($token)->postJson('/auth/api-tokens', ['name' => 't'])->assertStatus(201);
});

it('require_step_up false (default) allows token creation directly (back-compat)', function (): void {
    config(['auth_system.api_tokens.require_step_up' => false]);

    [, $token] = stepUpUser();

    $this->withToken($token)->postJson('/auth/api-tokens', ['name' => 't'])->assertStatus(201);
});
