<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Notification;
use Joe404\LaravelAuth\Models\DeletedAccount;
use Joe404\LaravelAuth\Tests\Fixtures\User;

beforeEach(function (): void {
    Notification::fake();

    \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'user', 'guard_name' => 'web']);

    config([
        'auth_system.account.deletion.enabled'          => true,
        'auth_system.account.deletion.self_service'     => true,
        'auth_system.account.deletion.require_password' => false,
    ]);
});

it('strips password and remember_token from the deletion snapshot, even when User has no $hidden', function (): void {
    // Host model misconfiguration the safety net exists to defend against.
    $visible = new class extends User {
        protected $hidden = [];
    };
    $model = $visible::class;

    config(['auth.providers.users.model' => $model]);

    $user = $model::create([
        'name'              => 'Visible Deletee',
        'email'             => 'sn@example.com',
        'password'          => bcrypt('whatever-long'),
        'email_verified_at' => now(),
        'is_active'         => true,
    ]);
    $token = $user->createToken('t')->plainTextToken;

    test()->withToken($token)->deleteJson('/auth/account')->assertOk();

    $row = DeletedAccount::where('original_user_id', $user->getKey())->first();
    expect($row)->not->toBeNull();
    expect($row->snapshot)->toBeArray();
    expect($row->snapshot)->not->toHaveKey('password');
    expect($row->snapshot)->not->toHaveKey('remember_token');
    expect($row->snapshot)->toHaveKey('email'); // non-sensitive fields preserved
});

it('honors a custom snapshot_strip_fields list when set', function (): void {
    $visible = new class extends User {
        protected $hidden = [];
    };
    $model = $visible::class;

    config([
        'auth.providers.users.model'                          => $model,
        'auth_system.account.deletion.snapshot_strip_fields'  => ['password', 'remember_token', 'name'],
    ]);

    $user = $model::create([
        'name'              => 'Sensitive Name',
        'email'             => 'sn2@example.com',
        'password'          => bcrypt('whatever-long'),
        'email_verified_at' => now(),
        'is_active'         => true,
    ]);
    $token = $user->createToken('t')->plainTextToken;

    test()->withToken($token)->deleteJson('/auth/account')->assertOk();

    $row = DeletedAccount::where('original_user_id', $user->getKey())->first();
    expect($row->snapshot)->not->toHaveKey('name'); // custom strip honored
    expect($row->snapshot)->not->toHaveKey('password');
});
