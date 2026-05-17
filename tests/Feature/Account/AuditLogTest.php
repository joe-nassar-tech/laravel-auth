<?php

declare(strict_types=1);

use Joe404\LaravelAuth\Models\AccountStatusLog;
use Joe404\LaravelAuth\Services\AccountAuditService;
use Joe404\LaravelAuth\Services\AccountDeletionService;
use Joe404\LaravelAuth\Services\AccountStatusService;
use Joe404\LaravelAuth\Support\AccountStatus;

beforeEach(function (): void {
    \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'user',  'guard_name' => 'web']);
    \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
});

it('records an entry whenever an admin changes status, with actor=admin + source=admin_endpoint', function (): void {
    $admin = $this->createUser(['email' => 'a@example.com', 'password' => bcrypt('Password123!')]);
    $admin->assignRole('admin');
    $token = $admin->createToken('test')->plainTextToken;

    $target = $this->createUser(['email' => 't@example.com', 'password' => bcrypt('Password123!')]);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/auth/admin/users/{$target->getKey()}/status", [
            'status'  => 'suspended',
            'reason'  => 'Spam reports.',
            'comment' => 'Third strike — see ticket #4711.',
        ])->assertStatus(200);

    $entry = AccountStatusLog::where('user_id', $target->getKey())->firstOrFail();
    expect($entry->actor_type)->toBe('admin');
    expect($entry->actor_id)->toBe($admin->getKey());
    expect($entry->from_status)->toBe(AccountStatus::ACTIVE);
    expect($entry->to_status)->toBe('suspended');
    expect($entry->reason)->toBe('Spam reports.');
    expect($entry->comment)->toBe('Third strike — see ticket #4711.');
    expect($entry->source)->toBe('admin_endpoint');
});

it('records actor=user + source=self_deactivate when the user deactivates themselves', function (): void {
    $user  = $this->createUser(['email' => 'pause@example.com', 'password' => bcrypt('Password123!')]);
    $token = $user->createToken('test')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/auth/account/deactivate', ['password' => 'Password123!', 'reason' => 'break'])
        ->assertStatus(200);

    $entry = AccountStatusLog::where('user_id', $user->getKey())->firstOrFail();
    expect($entry->actor_type)->toBe('user');
    expect($entry->actor_id)->toBe($user->getKey());
    expect($entry->source)->toBe('self_deactivate');
});

it('records actor=system entries for lazy auto-unban', function (): void {
    $user = $this->createUser([
        'email'             => 'expired@example.com',
        'password'          => bcrypt('Password123!'),
        'account_status'    => AccountStatus::SUSPENDED,
        'status_expires_at' => now()->subMinute(),
    ]);

    $this->postJson('/auth/login', [
        'email'    => 'expired@example.com',
        'password' => 'Password123!',
    ])->assertStatus(200);

    $entry = AccountStatusLog::where('user_id', $user->getKey())
        ->where('source', 'auto_unban_lazy')
        ->firstOrFail();

    expect($entry->actor_type)->toBe('system');
    expect($entry->actor_id)->toBeNull();
    expect($entry->from_status)->toBe(AccountStatus::SUSPENDED);
    expect($entry->to_status)->toBe(AccountStatus::ACTIVE);
});

it('suppresses system entries when log_system_actions=false', function (): void {
    config()->set('auth_system.account.audit.log_system_actions', false);

    $user = $this->createUser([
        'email'             => 'silent-system@example.com',
        'password'          => bcrypt('Password123!'),
        'account_status'    => AccountStatus::SUSPENDED,
        'status_expires_at' => now()->subMinute(),
    ]);

    $this->postJson('/auth/login', [
        'email'    => 'silent-system@example.com',
        'password' => 'Password123!',
    ])->assertStatus(200);

    expect(AccountStatusLog::where('user_id', $user->getKey())->count())->toBe(0);
});

it('writes no entries at all when audit is disabled', function (): void {
    config()->set('auth_system.account.audit.enabled', false);

    $admin = $this->createUser(['email' => 'off-admin@example.com', 'password' => bcrypt('Password123!')]);
    $admin->assignRole('admin');
    $token = $admin->createToken('test')->plainTextToken;

    $target = $this->createUser(['email' => 'off-target@example.com', 'password' => bcrypt('Password123!')]);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/auth/admin/users/{$target->getKey()}/status", ['status' => 'suspended'])
        ->assertStatus(200);

    expect(AccountStatusLog::where('user_id', $target->getKey())->count())->toBe(0);
});

it('returns paginated history filtered by actor_type', function (): void {
    $admin = $this->createUser(['email' => 'hist-admin@example.com', 'password' => bcrypt('Password123!')]);
    $admin->assignRole('admin');
    $token = $admin->createToken('test')->plainTextToken;

    $target = $this->createUser(['email' => 'hist-target@example.com', 'password' => bcrypt('Password123!')]);

    // Two admin changes + one system entry by simulating an auto-unban revert.
    app(AccountStatusService::class)->changeStatus(
        $target, 'suspended', 'r1', null,
        ['actor' => $admin, 'source' => 'admin_endpoint', 'comment' => 'c1'],
    );
    app(AccountStatusService::class)->changeStatus(
        $target, 'active', 'r2', null,
        ['actor' => $admin, 'source' => 'admin_endpoint', 'comment' => 'c2'],
    );
    app(AccountStatusService::class)->changeStatus(
        $target, 'suspended', 'system-test', null,
        ['actor_type' => 'system', 'source' => 'auto_unban_lazy'],
    );

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/auth/admin/users/{$target->getKey()}/status/history?actor_type=admin")
        ->assertStatus(200);

    expect($response->json('data.total'))->toBe(2);
    expect(collect($response->json('data.items'))->pluck('source')->all())
        ->each->toBe('admin_endpoint');
});

it('lets admin add a free-form note without changing status', function (): void {
    $admin = $this->createUser(['email' => 'note-admin@example.com', 'password' => bcrypt('Password123!')]);
    $admin->assignRole('admin');
    $token = $admin->createToken('test')->plainTextToken;

    $target = $this->createUser(['email' => 'note-target@example.com', 'password' => bcrypt('Password123!')]);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/auth/admin/users/{$target->getKey()}/notes", [
            'comment' => 'User emailed support twice asking about feature X. Waiting on product reply.',
        ])->assertStatus(201);

    $entry = AccountStatusLog::where('user_id', $target->getKey())->firstOrFail();
    expect($entry->action)->toBe(AccountAuditService::ACTION_NOTE);
    expect($entry->actor_type)->toBe('admin');
    expect($entry->actor_id)->toBe($admin->getKey());
    expect($entry->from_status)->toBeNull();
    expect($entry->to_status)->toBeNull();
    expect($entry->source)->toBe('admin_note');

    // Status itself is untouched.
    $target->refresh();
    expect($target->account_status)->toBe(AccountStatus::ACTIVE);
});

it('returns 404 when notes endpoint is disabled', function (): void {
    config()->set('auth_system.account.audit.notes.enabled', false);

    $admin = $this->createUser(['email' => 'no-notes-admin@example.com', 'password' => bcrypt('Password123!')]);
    $admin->assignRole('admin');
    $token = $admin->createToken('test')->plainTextToken;

    $target = $this->createUser(['email' => 'no-notes-target@example.com', 'password' => bcrypt('Password123!')]);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/auth/admin/users/{$target->getKey()}/notes", ['comment' => 'hi'])
        ->assertStatus(404);
});

it('returns 404 when history endpoint is disabled', function (): void {
    config()->set('auth_system.account.audit.history.enabled', false);

    $admin = $this->createUser(['email' => 'no-hist-admin@example.com', 'password' => bcrypt('Password123!')]);
    $admin->assignRole('admin');
    $token = $admin->createToken('test')->plainTextToken;

    $target = $this->createUser(['email' => 'no-hist-target@example.com', 'password' => bcrypt('Password123!')]);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/auth/admin/users/{$target->getKey()}/status/history")
        ->assertStatus(404);
});
