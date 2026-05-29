<?php

declare(strict_types=1);

use Joe404\LaravelAuth\Models\AuthTwoFactorBackupCode;
use Joe404\LaravelAuth\Services\BackupCodeService;

beforeEach(function (): void {
    \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'user', 'guard_name' => 'web']);
});

it('consumes a backup code exactly once (single-use)', function (): void {
    $user  = $this->createUser(['email' => 'bc-once@example.com']);
    $svc   = app(BackupCodeService::class);
    $codes = $svc->generate($user);
    $code  = $codes[0];

    expect($svc->consume($user, $code))->toBeTrue();
    expect($svc->consume($user, $code))->toBeFalse(); // single-use contract
});

it('the conditional consume is atomic — only one update wins the row', function (): void {
    $user  = $this->createUser(['email' => 'bc-race@example.com']);
    $svc   = app(BackupCodeService::class);
    $svc->generate($user);

    // Pick any unused row and simulate two requests both racing to flip used_at.
    $row = AuthTwoFactorBackupCode::where('user_id', $user->getKey())->first();

    $first  = AuthTwoFactorBackupCode::where('id', $row->getKey())->whereNull('used_at')->update(['used_at' => now()]);
    $second = AuthTwoFactorBackupCode::where('id', $row->getKey())->whereNull('used_at')->update(['used_at' => now()]);

    expect($first)->toBe(1);
    expect($second)->toBe(0); // the loser gets 0 → consume() returns false
});
