<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Notification;
use Joe404\LaravelAuth\Notifications\AccountDeactivatedNotification;
use Joe404\LaravelAuth\Notifications\AccountReactivatedNotification;
use Joe404\LaravelAuth\Support\AccountStatus;

beforeEach(function (): void {
    Notification::fake();
    \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'user', 'guard_name' => 'web']);
});

it('user can self-deactivate, sessions are revoked, notification sent', function (): void {
    $user  = $this->createUser(['email' => 'pause@example.com', 'password' => bcrypt('Password123!')]);
    $token = $user->createToken('test')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/auth/account/deactivate', [
            'password' => 'Password123!',
            'reason'   => 'Taking a break.',
        ])->assertStatus(200);

    expect($response->json('data.status'))->toBe(AccountStatus::DEACTIVATED);
    expect($response->json('data.auto_reactivate_on_login'))->toBeTrue();

    $user->refresh();
    expect($user->account_status)->toBe(AccountStatus::DEACTIVATED);
    expect($user->trashed())->toBeFalse(); // distinct from delete — no soft-delete
    expect($user->tokens()->count())->toBe(0);

    Notification::assertSentTo($user, AccountDeactivatedNotification::class);
});

it('rejects deactivation with wrong password', function (): void {
    $user  = $this->createUser(['email' => 'pw-pause@example.com', 'password' => bcrypt('Password123!')]);
    $token = $user->createToken('test')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/auth/account/deactivate', ['password' => 'wrong'])
        ->assertStatus(422);

    $user->refresh();
    expect($user->account_status)->not->toBe(AccountStatus::DEACTIVATED);
});

it('returns 403 when self-service deactivation is disabled', function (): void {
    config()->set('auth_system.account.deactivation.self_service', false);

    $user  = $this->createUser(['email' => 'no-pause@example.com', 'password' => bcrypt('Password123!')]);
    $token = $user->createToken('test')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/auth/account/deactivate', ['password' => 'Password123!'])
        ->assertStatus(403);
});

it('auto-reactivates a deactivated user on successful login and sends notification', function (): void {
    $user = $this->createUser([
        'email'          => 'comeback@example.com',
        'password'       => bcrypt('Password123!'),
        'account_status' => AccountStatus::DEACTIVATED,
    ]);

    $response = $this->postJson('/auth/login', [
        'email'    => 'comeback@example.com',
        'password' => 'Password123!',
    ])->assertStatus(200);

    expect($response->json('data.token'))->not->toBeNull();

    $user->refresh();
    expect($user->account_status)->toBe(AccountStatus::ACTIVE);

    Notification::assertSentTo($user, AccountReactivatedNotification::class);
});

it('skips auto-reactivate when feature is disabled (login is rejected)', function (): void {
    config()->set('auth_system.account.deactivation.auto_reactivate_on_login', false);
    // Need to explicitly block deactivated when auto-reactivate is off.
    config()->set('auth_system.account.status.login_blocked', ['disabled', 'suspended', 'deactivated']);
    config()->set('auth_system.errors.account_deactivated', 'Your account is deactivated. Reactivation flow is disabled.');

    $this->createUser([
        'email'          => 'stuck@example.com',
        'password'       => bcrypt('Password123!'),
        'account_status' => AccountStatus::DEACTIVATED,
    ]);

    $this->postJson('/auth/login', [
        'email'    => 'stuck@example.com',
        'password' => 'Password123!',
    ])->assertStatus(401);
});
