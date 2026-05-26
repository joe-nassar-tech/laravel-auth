<?php

declare(strict_types=1);

use Joe404\LaravelAuth\Models\AuthTwoFactorBackupCode;
use Joe404\LaravelAuth\Services\BackupCodeService;

it('generates the configured number of single-use backup codes', function () {
    config([
        'auth_system.two_factor.backup_codes.enabled' => true,
        'auth_system.two_factor.backup_codes.count'   => 5,
        'auth_system.two_factor.backup_codes.length'  => 10,
    ]);

    $user  = $this->createUser();
    $codes = app(BackupCodeService::class)->generate($user);

    expect($codes)->toHaveCount(5);
    expect(AuthTwoFactorBackupCode::where('user_id', $user->getKey())->count())->toBe(5);

    foreach ($codes as $code) {
        expect(strlen($code))->toBe(10);
    }
});

it('consumes a backup code on first use and rejects the second use', function () {
    $user  = $this->createUser();
    $codes = app(BackupCodeService::class)->generate($user);
    $first = $codes[0];

    expect(app(BackupCodeService::class)->consume($user, $first))->toBeTrue();
    expect(app(BackupCodeService::class)->consume($user, $first))->toBeFalse();
});

it('regenerating backup codes invalidates the previous set', function () {
    $user = $this->createUser();
    $svc  = app(BackupCodeService::class);

    $first   = $svc->generate($user);
    $second  = $svc->generate($user);

    // None of the first set should consume after rotation.
    foreach ($first as $code) {
        expect($svc->consume($user, $code))->toBeFalse();
    }

    // The new set works.
    expect($svc->consume($user, $second[0]))->toBeTrue();
});
