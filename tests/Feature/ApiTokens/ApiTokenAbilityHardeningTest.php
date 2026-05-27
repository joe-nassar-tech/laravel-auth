<?php

declare(strict_types=1);

beforeEach(function (): void {
    config()->set('auth_system.api_tokens.enabled', true);

    foreach (['super-admin', 'admin', 'user'] as $role) {
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
    }
});

function abilityUserToken(): string
{
    $user = test()->createUser(['email' => 'u_' . uniqid() . '@example.com']);
    $user->assignRole('user');

    return $user->createToken('test')->plainTextToken;
}

function abilityAdminToken(): string
{
    $admin = test()->createUser(['email' => 'a_' . uniqid() . '@example.com']);
    $admin->assignRole('admin');

    return $admin->createToken('test')->plainTextToken;
}

it('strict: rejects a self-issued wildcard ability', function (): void {
    config([
        'auth_system.api_tokens.strict_abilities'   => true,
        'auth_system.api_tokens.grantable_abilities' => ['read'],
    ]);

    $this->withToken(abilityUserToken())
        ->postJson('/auth/api-tokens', ['name' => 't', 'abilities' => ['*']])
        ->assertStatus(422);
});

it('strict: rejects an ability outside the grantable allow-list', function (): void {
    config([
        'auth_system.api_tokens.strict_abilities'   => true,
        'auth_system.api_tokens.grantable_abilities' => ['read'],
    ]);

    $this->withToken(abilityUserToken())
        ->postJson('/auth/api-tokens', ['name' => 't', 'abilities' => ['read', 'write']])
        ->assertStatus(422);
});

it('strict: allows abilities that are on the allow-list', function (): void {
    config([
        'auth_system.api_tokens.strict_abilities'   => true,
        'auth_system.api_tokens.grantable_abilities' => ['read'],
    ]);

    $this->withToken(abilityUserToken())
        ->postJson('/auth/api-tokens', ['name' => 't', 'abilities' => ['read']])
        ->assertStatus(201);
});

it('non-strict default still lets a user request wildcard (back-compat)', function (): void {
    config(['auth_system.api_tokens.strict_abilities' => false]);

    $this->withToken(abilityUserToken())
        ->postJson('/auth/api-tokens', ['name' => 't', 'abilities' => ['*']])
        ->assertStatus(201);
});

it('admin can still issue a wildcard token while strict mode is on', function (): void {
    config(['auth_system.api_tokens.strict_abilities' => true]);

    $this->withToken(abilityAdminToken())
        ->postJson('/auth/admin/api-tokens', ['name' => 't', 'abilities' => ['*']])
        ->assertStatus(201);
});

it('caps a non-expiring user token at max_ttl_days', function (): void {
    config(['auth_system.api_tokens.max_ttl_days' => 30]);

    $response = $this->withToken(abilityUserToken())
        ->postJson('/auth/api-tokens', ['name' => 't'])
        ->assertStatus(201);

    expect($response->json('data.token.expires_at'))->not->toBeNull();
});

it('strict: an empty abilities array cannot bypass the allow-list via abilities_default', function (): void {
    // abilities_default is deliberately broader than the grantable allow-list.
    config([
        'auth_system.api_tokens.strict_abilities'    => true,
        'auth_system.api_tokens.grantable_abilities' => ['read'],
        'auth_system.api_tokens.abilities_default'   => ['read', 'write'],
    ]);

    // Sending [] used to pass the (empty) strict check and then expand to
    // abilities_default ('write' included) inside the service. Now rejected.
    $this->withToken(abilityUserToken())
        ->postJson('/auth/api-tokens', ['name' => 't', 'abilities' => []])
        ->assertStatus(422);
});

it('strict: an empty abilities array resolves to the default when it is within the allow-list', function (): void {
    config([
        'auth_system.api_tokens.strict_abilities'    => true,
        'auth_system.api_tokens.grantable_abilities' => ['read'],
        'auth_system.api_tokens.abilities_default'   => ['read'],
    ]);

    $response = $this->withToken(abilityUserToken())
        ->postJson('/auth/api-tokens', ['name' => 't', 'abilities' => []])
        ->assertStatus(201);

    expect($response->json('data.token.abilities'))->toBe(['read']);
});
