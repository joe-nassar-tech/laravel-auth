<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Joe404\LaravelAuth\Jobs\PurgeExpiredAccountDeletions;
use Joe404\LaravelAuth\Models\DeletedAccount;
use Joe404\LaravelAuth\Services\AccountDeletionService;

beforeEach(function (): void {
    \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'user', 'guard_name' => 'web']);
});

it('nulls unique columns on the users row when the worker runs after grace', function (): void {
    $user = $this->createUser(['email' => 'purge@example.com', 'password' => bcrypt('Password123!')]);

    app(AccountDeletionService::class)->delete($user);

    DeletedAccount::where('original_user_id', $user->getKey())
        ->update(['scheduled_purge_at' => now()->subMinute()]);

    (new PurgeExpiredAccountDeletions())->handle(app(AccountDeletionService::class));

    $row = DB::table('users')->where('id', $user->getKey())->first();
    expect($row->email)->toBeNull();

    $entry = DeletedAccount::where('original_user_id', $user->getKey())->firstOrFail();
    expect($entry->purged_at)->not->toBeNull();
});

it('hard-deletes the users row when configured', function (): void {
    config()->set('auth_system.account.deletion.hard_delete_after_grace', true);

    $user = $this->createUser(['email' => 'gone@example.com', 'password' => bcrypt('Password123!')]);
    $id   = $user->getKey();

    app(AccountDeletionService::class)->delete($user);
    DeletedAccount::where('original_user_id', $id)->update(['scheduled_purge_at' => now()->subMinute()]);

    (new PurgeExpiredAccountDeletions())->handle(app(AccountDeletionService::class));

    expect(DB::table('users')->where('id', $id)->exists())->toBeFalse();
    expect(DeletedAccount::where('original_user_id', $id)->exists())->toBeTrue();
});

it('skips entries that have not reached scheduled_purge_at', function (): void {
    $user = $this->createUser(['email' => 'still-grace@example.com', 'password' => bcrypt('Password123!')]);

    app(AccountDeletionService::class)->delete($user);

    (new PurgeExpiredAccountDeletions())->handle(app(AccountDeletionService::class));

    $entry = DeletedAccount::where('original_user_id', $user->getKey())->firstOrFail();
    expect($entry->purged_at)->toBeNull();
});
