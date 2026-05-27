<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    Notification::fake();

    foreach (['super-admin', 'admin', 'user'] as $role) {
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
    }

    config([
        'auth_system.account.status.enabled' => true,
        'auth_system.account.status.admin_actions.enforce_role_hierarchy' => true,
    ]);
});

function hierActor(string $role): string
{
    $user = test()->createUser(['email' => $role . '_' . uniqid() . '@example.com']);
    $user->assignRole($role);

    return $user->createToken('t')->plainTextToken;
}

function hierTarget(string $role): \Joe404\LaravelAuth\Tests\Fixtures\User
{
    $user = test()->createUser(['email' => 'target_' . uniqid() . '@example.com']);
    $user->assignRole($role);

    return $user;
}

it('blocks an admin from changing a super-admin', function (): void {
    $target = hierTarget('super-admin');

    $this->withToken(hierActor('admin'))
        ->postJson("/auth/admin/users/{$target->getKey()}/status", ['status' => 'suspended'])
        ->assertStatus(403);
});

it('blocks an admin from changing a peer admin', function (): void {
    $target = hierTarget('admin');

    $this->withToken(hierActor('admin'))
        ->postJson("/auth/admin/users/{$target->getKey()}/status", ['status' => 'suspended'])
        ->assertStatus(403);
});

it('blocks an admin from changing their own status', function (): void {
    $admin = test()->createUser(['email' => 'self_' . uniqid() . '@example.com']);
    $admin->assignRole('admin');
    $token = $admin->createToken('t')->plainTextToken;

    $this->withToken($token)
        ->postJson("/auth/admin/users/{$admin->getKey()}/status", ['status' => 'suspended'])
        ->assertStatus(403);
});

it('allows a super-admin to change an admin (lower rank)', function (): void {
    $target = hierTarget('admin');

    $this->withToken(hierActor('super-admin'))
        ->postJson("/auth/admin/users/{$target->getKey()}/status", ['status' => 'suspended'])
        ->assertOk();
});

it('rejects setting status=deleted via the status endpoint', function (): void {
    $target = hierTarget('user');

    $this->withToken(hierActor('super-admin'))
        ->postJson("/auth/admin/users/{$target->getKey()}/status", ['status' => 'deleted'])
        ->assertStatus(422);
});

it('does not enforce hierarchy when the flag is off (back-compat)', function (): void {
    config(['auth_system.account.status.admin_actions.enforce_role_hierarchy' => false]);

    $target = hierTarget('super-admin');

    $this->withToken(hierActor('admin'))
        ->postJson("/auth/admin/users/{$target->getKey()}/status", ['status' => 'suspended'])
        ->assertOk();
});
