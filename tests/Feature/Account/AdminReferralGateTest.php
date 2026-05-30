<?php

declare(strict_types=1);

use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    foreach (['super-admin', 'admin', 'user'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }

    config(['auth_system.referral_code.enabled' => true]);
});

it('admin referral routes accept the default admin role via admin_ability', function (): void {
    $admin = test()->createUser(['email' => 'ar-role@example.com']);
    $admin->assignRole('admin');
    $token = $admin->createToken('t')->plainTextToken;

    test()->withToken($token)->getJson('/auth/admin/referrals')->assertOk();
});

it('admin referral routes accept a Spatie permission when admin_middleware is set', function (): void {
    Permission::firstOrCreate(['name' => 'referrals.manage', 'guard_name' => 'web']);
    config(['auth_system.referral_code.admin_middleware' => 'super-admin|referrals.manage']);

    $actor = test()->createUser(['email' => 'ar-perm@example.com']);
    $actor->givePermissionTo('referrals.manage');
    $token = $actor->createToken('t')->plainTextToken;

    test()->withToken($token)->getJson('/auth/admin/referrals')->assertOk();
});

it('admin referral routes reject a user without role or permission', function (): void {
    $actor = test()->createUser(['email' => 'ar-deny@example.com']);
    $actor->assignRole('user'); // not super-admin, no permission
    $token = $actor->createToken('t')->plainTextToken;

    test()->withToken($token)->getJson('/auth/admin/referrals')->assertStatus(403);
});
