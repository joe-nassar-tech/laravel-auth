<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Joe404\LaravelAuth\Events\AccountStatusChanged;
use Joe404\LaravelAuth\Jobs\RevertExpiredAccountStatuses;
use Joe404\LaravelAuth\Services\AccountStatusService;
use Joe404\LaravelAuth\Support\AccountStatus;

beforeEach(function (): void {
    \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'user', 'guard_name' => 'web']);
    \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
});

it('stores status_expires_at when duration_minutes is supplied', function (): void {
    $admin  = $this->createUser(['email' => 'admin@example.com', 'password' => bcrypt('Password123!')]);
    $admin->assignRole('admin');
    $token  = $admin->createToken('test')->plainTextToken;

    $target = $this->createUser(['email' => 'target@example.com', 'password' => bcrypt('Password123!')]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/auth/admin/users/{$target->getKey()}/status", [
            'status'           => 'suspended',
            'reason'           => 'Cooling-off period.',
            'duration_minutes' => 120,
        ])->assertStatus(200);

    expect($response->json('data.status'))->toBe('suspended');
    expect($response->json('data.status_expires_at'))->not->toBeNull();

    $target->refresh();
    expect(abs($target->status_expires_at->diffInMinutes(now())))->toBeGreaterThan(110);
    expect($target->status_expires_at->isFuture())->toBeTrue();
});

it('lazy-auto-unbans on login the instant the ban expires', function (): void {
    $user = $this->createUser([
        'email'             => 'banned@example.com',
        'password'          => bcrypt('Password123!'),
        'account_status'    => AccountStatus::SUSPENDED,
        'status_expires_at' => now()->subMinute(),
    ]);

    Event::fake([AccountStatusChanged::class]);

    $this->postJson('/auth/login', [
        'email'    => 'banned@example.com',
        'password' => 'Password123!',
    ])->assertStatus(200);

    $user->refresh();
    expect($user->account_status)->toBe(AccountStatus::ACTIVE);
    expect($user->status_expires_at)->toBeNull();

    Event::assertDispatched(AccountStatusChanged::class);
});

it('keeps the ban active when expiry is still in the future', function (): void {
    $this->createUser([
        'email'             => 'still-banned@example.com',
        'password'          => bcrypt('Password123!'),
        'account_status'    => AccountStatus::SUSPENDED,
        'status_expires_at' => now()->addHour(),
    ]);

    $this->postJson('/auth/login', [
        'email'    => 'still-banned@example.com',
        'password' => 'Password123!',
    ])->assertStatus(401);
});

it('worker reverts expired bans and fires the event', function (): void {
    $user = $this->createUser([
        'email'             => 'sweep@example.com',
        'password'          => bcrypt('Password123!'),
        'account_status'    => AccountStatus::DISABLED,
        'status_expires_at' => now()->subMinutes(10),
    ]);

    Event::fake([AccountStatusChanged::class]);

    (new RevertExpiredAccountStatuses())->handle(app(AccountStatusService::class));

    $user->refresh();
    expect($user->account_status)->toBe(AccountStatus::ACTIVE);
    expect($user->status_expires_at)->toBeNull();

    Event::assertDispatched(AccountStatusChanged::class);
});

it('skips users whose expiry has not arrived yet in the worker', function (): void {
    $user = $this->createUser([
        'email'             => 'skip@example.com',
        'password'          => bcrypt('Password123!'),
        'account_status'    => AccountStatus::SUSPENDED,
        'status_expires_at' => now()->addHour(),
    ]);

    (new RevertExpiredAccountStatuses())->handle(app(AccountStatusService::class));

    $user->refresh();
    expect($user->account_status)->toBe(AccountStatus::SUSPENDED);
});

it('clears expiry when status is manually set back to active', function (): void {
    $user = $this->createUser([
        'email'             => 'pardon@example.com',
        'password'          => bcrypt('Password123!'),
        'account_status'    => AccountStatus::SUSPENDED,
        'status_expires_at' => now()->addDays(30),
    ]);

    app(AccountStatusService::class)->changeStatus($user, AccountStatus::ACTIVE, 'Appeal granted.');

    $user->refresh();
    expect($user->status_expires_at)->toBeNull();
});

it('rejects timed-ban inputs for statuses not in temporary_statuses', function (): void {
    $admin = $this->createUser(['email' => 'gate-admin@example.com', 'password' => bcrypt('Password123!')]);
    $admin->assignRole('admin');
    $token = $admin->createToken('test')->plainTextToken;

    $target = $this->createUser(['email' => 'gate-target@example.com', 'password' => bcrypt('Password123!')]);

    // Default temporary_statuses = ['suspended']; "disabled" should reject expiries.
    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/auth/admin/users/{$target->getKey()}/status", [
            'status'           => 'disabled',
            'duration_minutes' => 60,
        ])->assertStatus(422);

    expect($response->json('errors.status'))->not->toBeNull();

    $target->refresh();
    expect($target->account_status)->not->toBe('disabled');
});

it('permanent (no expiry) ban on a temporary-capable status stays forever', function (): void {
    $admin = $this->createUser(['email' => 'perm-admin@example.com', 'password' => bcrypt('Password123!')]);
    $admin->assignRole('admin');
    $token = $admin->createToken('test')->plainTextToken;

    $target = $this->createUser(['email' => 'perm-target@example.com', 'password' => bcrypt('Password123!')]);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/auth/admin/users/{$target->getKey()}/status", [
            'status' => 'suspended',
            'reason' => 'No end date.',
        ])->assertStatus(200);

    $target->refresh();
    expect($target->account_status)->toBe('suspended');
    expect($target->status_expires_at)->toBeNull();

    // Worker must not touch a ban that has no expiry, even years later.
    (new \Joe404\LaravelAuth\Jobs\RevertExpiredAccountStatuses())
        ->handle(app(\Joe404\LaravelAuth\Services\AccountStatusService::class));

    $target->refresh();
    expect($target->account_status)->toBe('suspended');
});

it('allows extending temporary_statuses via config', function (): void {
    config()->set('auth_system.account.status.auto_unban.temporary_statuses', ['suspended', 'disabled']);

    $admin = $this->createUser(['email' => 'ext-admin@example.com', 'password' => bcrypt('Password123!')]);
    $admin->assignRole('admin');
    $token = $admin->createToken('test')->plainTextToken;

    $target = $this->createUser(['email' => 'ext-target@example.com', 'password' => bcrypt('Password123!')]);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/auth/admin/users/{$target->getKey()}/status", [
            'status'           => 'disabled',
            'duration_minutes' => 60,
        ])->assertStatus(200);

    $target->refresh();
    expect($target->account_status)->toBe('disabled');
    expect($target->status_expires_at)->not->toBeNull();
});

it('respects auto_unban.enabled=false', function (): void {
    config()->set('auth_system.account.status.auto_unban.enabled', false);

    $user = $this->createUser([
        'email'             => 'no-revert@example.com',
        'password'          => bcrypt('Password123!'),
        'account_status'    => AccountStatus::SUSPENDED,
        'status_expires_at' => now()->subMinute(),
    ]);

    (new RevertExpiredAccountStatuses())->handle(app(AccountStatusService::class));

    $user->refresh();
    expect($user->account_status)->toBe(AccountStatus::SUSPENDED);

    $this->postJson('/auth/login', [
        'email'    => 'no-revert@example.com',
        'password' => 'Password123!',
    ])->assertStatus(401);
});
