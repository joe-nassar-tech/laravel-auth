<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    Notification::fake();

    foreach (['super-admin', 'admin', 'user'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
});

it('admin-gate accepts a user with the configured permission (no admin role)', function (): void {
    Permission::firstOrCreate(['name' => 'users.manage-status', 'guard_name' => 'web']);
    config(['auth_system.account.status.admin_middleware' => 'super-admin|users.manage-status']);

    $actor = test()->createUser(['email' => 'perm-actor@example.com']);
    $actor->givePermissionTo('users.manage-status');
    $token = $actor->createToken('t')->plainTextToken;

    $target = test()->createUser(['email' => 'perm-target@example.com']);
    $target->assignRole('user');

    test()->withToken($token)
        ->postJson("/auth/admin/users/{$target->getKey()}/status", ['status' => 'suspended'])
        ->assertOk();
});

it('admin-gate rejects a user without the role OR the permission', function (): void {
    Permission::firstOrCreate(['name' => 'users.manage-status', 'guard_name' => 'web']);
    config(['auth_system.account.status.admin_middleware' => 'super-admin|users.manage-status']);

    $actor = test()->createUser(['email' => 'noperm@example.com']);
    $actor->assignRole('user'); // not super-admin, no permission either
    $token = $actor->createToken('t')->plainTextToken;

    $target = test()->createUser(['email' => 'np-target@example.com']);
    $target->assignRole('user');

    test()->withToken($token)
        ->postJson("/auth/admin/users/{$target->getKey()}/status", ['status' => 'suspended'])
        ->assertStatus(403);
});

it('admin-gate falls back to admin_ability (role:) when admin_middleware is unset', function (): void {
    config(['auth_system.account.status.admin_middleware' => null]);

    $actor = test()->createUser(['email' => 'role-actor@example.com']);
    $actor->assignRole('admin');
    $token = $actor->createToken('t')->plainTextToken;

    $target = test()->createUser(['email' => 'role-target@example.com']);
    $target->assignRole('user');

    test()->withToken($token)
        ->postJson("/auth/admin/users/{$target->getKey()}/status", ['status' => 'suspended'])
        ->assertOk();
});
