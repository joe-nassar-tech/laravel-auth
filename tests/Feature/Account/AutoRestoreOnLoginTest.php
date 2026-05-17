<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Notification;
use Joe404\LaravelAuth\Models\DeletedAccount;
use Joe404\LaravelAuth\Notifications\AccountRestoredNotification;
use Joe404\LaravelAuth\Services\AccountDeletionService;
use Joe404\LaravelAuth\Support\AccountStatus;

beforeEach(function (): void {
    Notification::fake();
    \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'user', 'guard_name' => 'web']);
});

it('auto-restores a soft-deleted user on successful login during grace', function (): void {
    $user = $this->createUser([
        'email'    => 'comeback@example.com',
        'password' => bcrypt('Password123!'),
    ]);

    // Simulate a previous self-delete.
    app(AccountDeletionService::class)->delete($user, 'changed-mind');

    $user->refresh();
    expect($user->trashed())->toBeTrue();
    expect(DeletedAccount::where('original_user_id', $user->getKey())->count())->toBe(1);

    $response = $this->postJson('/auth/login', [
        'email'    => 'comeback@example.com',
        'password' => 'Password123!',
    ])->assertStatus(200);

    expect($response->json('data.token'))->not->toBeNull();

    $user->refresh();
    expect($user->trashed())->toBeFalse();
    expect($user->account_status)->toBe(AccountStatus::ACTIVE);
    expect(DeletedAccount::where('original_user_id', $user->getKey())->count())->toBe(0);

    Notification::assertSentTo($user, AccountRestoredNotification::class);
});

it('does not auto-restore when grace has expired', function (): void {
    $user = $this->createUser([
        'email'    => 'too-late@example.com',
        'password' => bcrypt('Password123!'),
    ]);

    app(AccountDeletionService::class)->delete($user);

    // Push the audit row past its grace window.
    DeletedAccount::where('original_user_id', $user->getKey())
        ->update(['scheduled_purge_at' => now()->subDay()]);

    $this->postJson('/auth/login', [
        'email'    => 'too-late@example.com',
        'password' => 'Password123!',
    ])->assertStatus(401);

    $user->refresh();
    expect($user->trashed())->toBeTrue();
});
