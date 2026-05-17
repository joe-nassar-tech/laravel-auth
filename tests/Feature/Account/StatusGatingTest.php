<?php

declare(strict_types=1);

use Joe404\LaravelAuth\Support\AccountStatus;

beforeEach(function (): void {
    \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'user', 'guard_name' => 'web']);
});

it('blocks login when status is disabled', function (): void {
    $this->createUser([
        'email'          => 'disabled@example.com',
        'password'       => bcrypt('Password123!'),
        'account_status' => AccountStatus::DISABLED,
    ]);

    $response = $this->postJson('/auth/login', [
        'email'    => 'disabled@example.com',
        'password' => 'Password123!',
    ])->assertStatus(401);

    expect($response->json('success'))->toBeFalse();
});

it('blocks login when status is suspended', function (): void {
    $this->createUser([
        'email'          => 'suspended@example.com',
        'password'       => bcrypt('Password123!'),
        'account_status' => AccountStatus::SUSPENDED,
    ]);

    $this->postJson('/auth/login', [
        'email'    => 'suspended@example.com',
        'password' => 'Password123!',
    ])->assertStatus(401);
});

it('allows login when status is active', function (): void {
    $this->createUser([
        'email'          => 'active@example.com',
        'password'       => bcrypt('Password123!'),
        'account_status' => AccountStatus::ACTIVE,
    ]);

    $this->postJson('/auth/login', [
        'email'    => 'active@example.com',
        'password' => 'Password123!',
    ])->assertStatus(200);
});

it('allows login when account status feature is disabled even with disabled status', function (): void {
    config()->set('auth_system.account.status.enabled', false);

    $this->createUser([
        'email'          => 'feat-off@example.com',
        'password'       => bcrypt('Password123!'),
        'account_status' => AccountStatus::DISABLED,
    ]);

    $this->postJson('/auth/login', [
        'email'    => 'feat-off@example.com',
        'password' => 'Password123!',
    ])->assertStatus(200);
});
