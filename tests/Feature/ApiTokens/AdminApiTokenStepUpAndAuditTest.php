<?php

declare(strict_types=1);

use Joe404\LaravelAuth\Models\AuthApiToken;

beforeEach(function (): void {
    config()->set('auth_system.api_tokens.enabled', true);

    foreach (['super-admin', 'admin', 'user'] as $role) {
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
    }
});

function adminWithPasswordToken(): array
{
    $admin = test()->createUser([
        'email'    => 'a_' . uniqid() . '@example.com',
        'password' => bcrypt('Password123!'),
    ]);
    $admin->assignRole('admin');

    return [$admin, $admin->createToken('t')->plainTextToken];
}

it('admin_require_step_up: blocks admin token creation without a fresh step-up', function (): void {
    config([
        'auth_system.api_tokens.admin_require_step_up' => true,
        'auth_system.two_factor.step_up_mode'          => 'password_confirm',
    ]);

    [, $token] = adminWithPasswordToken();

    test()->withToken($token)
        ->postJson('/auth/admin/api-tokens', ['name' => 't'])
        ->assertStatus(403)
        ->assertJsonPath('data.step_up', 'password_confirm');
});

it('admin_require_step_up: allows admin token creation after a password confirm', function (): void {
    config([
        'auth_system.api_tokens.admin_require_step_up' => true,
        'auth_system.two_factor.step_up_mode'          => 'password_confirm',
    ]);

    [, $token] = adminWithPasswordToken();

    test()->withToken($token)
        ->postJson('/auth/password/confirm', ['password' => 'Password123!'])
        ->assertOk();

    test()->withToken($token)
        ->postJson('/auth/admin/api-tokens', ['name' => 't'])
        ->assertStatus(201);
});

it('admin_require_step_up false (default) lets admin create tokens directly', function (): void {
    config(['auth_system.api_tokens.admin_require_step_up' => false]);

    [, $token] = adminWithPasswordToken();

    test()->withToken($token)
        ->postJson('/auth/admin/api-tokens', ['name' => 't'])
        ->assertStatus(201);
});

it('admin-created tokens record the creator for audit (created_by_*)', function (): void {
    [$admin, $token] = adminWithPasswordToken();

    $resp = test()->withToken($token)
        ->postJson('/auth/admin/api-tokens', ['name' => 'CI Bot'])
        ->assertStatus(201);

    $row = AuthApiToken::find($resp->json('data.token.id'));

    expect($row->created_by_id)->toBe($admin->getKey());
    expect($row->created_by_type)->toBe(get_class($admin));
    expect($row->owner_id)->toBeNull();      // admin-issued ⇒ unowned
    expect($row->owner_type)->toBeNull();
});

it('user-issued tokens record the user as both owner and creator', function (): void {
    $user = test()->createUser(['email' => 'u_' . uniqid() . '@example.com']);
    $user->assignRole('user');
    $token = $user->createToken('t')->plainTextToken;

    $resp = test()->withToken($token)
        ->postJson('/auth/api-tokens', ['name' => 'mine'])
        ->assertStatus(201);

    $row = AuthApiToken::find($resp->json('data.token.id'));

    expect($row->owner_id)->toBe($user->getKey());
    expect($row->created_by_id)->toBe($user->getKey());
    expect($row->created_by_type)->toBe(get_class($user));
});
