<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Notification;
use Joe404\LaravelAuth\Models\DeletedAccount;
use Joe404\LaravelAuth\Notifications\AccountDeletedNotification;
use Joe404\LaravelAuth\Support\AccountStatus;

beforeEach(function (): void {
    Notification::fake();
    \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'user', 'guard_name' => 'web']);
});

it('soft-deletes the user, snapshots to deleted_accounts and notifies', function (): void {
    $user  = $this->createUser(['email' => 'bye@example.com', 'password' => bcrypt('Password123!')]);
    $token = $user->createToken('test')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson('/auth/account', ['password' => 'Password123!', 'reason' => 'I am done.'])
        ->assertStatus(200);

    expect($response->json('data.grace_days'))->toBe(30);
    expect($response->json('data.auto_restore'))->toBeTrue();

    $user->refresh();
    expect($user->trashed())->toBeTrue();
    expect($user->account_status)->toBe(AccountStatus::DELETED);

    $entry = DeletedAccount::where('original_user_id', $user->getKey())->firstOrFail();
    expect($entry->email)->toBe('bye@example.com');
    expect($entry->delete_reason)->toBe('I am done.');
    expect($entry->scheduled_purge_at->isFuture())->toBeTrue();

    Notification::assertSentTo($user, AccountDeletedNotification::class);
});

it('rejects deletion with wrong password', function (): void {
    $user  = $this->createUser(['email' => 'pw@example.com', 'password' => bcrypt('Password123!')]);
    $token = $user->createToken('test')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson('/auth/account', ['password' => 'wrong'])
        ->assertStatus(422);

    $user->refresh();
    expect($user->trashed())->toBeFalse();
});

it('returns 403 when self-service deletion is disabled', function (): void {
    config()->set('auth_system.account.deletion.self_service', false);

    $user  = $this->createUser(['email' => 'closed@example.com', 'password' => bcrypt('Password123!')]);
    $token = $user->createToken('test')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson('/auth/account', ['password' => 'Password123!'])
        ->assertStatus(403);
});
