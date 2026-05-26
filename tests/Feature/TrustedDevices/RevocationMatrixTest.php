<?php

declare(strict_types=1);

use Joe404\LaravelAuth\Models\AuthTrustedDevice;
use Joe404\LaravelAuth\Services\TrustedDeviceService;

beforeEach(function () {
    config([
        'auth_system.trusted_devices.enabled' => true,
        'auth_system.trusted_devices.level_assignment' => 'time',
        'auth_system.trusted_devices.thresholds_days' => ['low' => 15, 'medium' => 60, 'high' => 90],
    ]);
});

it('low cannot revoke any other device', function () {
    $user   = $this->createUser();
    $actor  = makeTrustedDevice($user, 'low', daysOld: 20);
    $target = makeTrustedDevice($user, 'low', daysOld: 25);

    expect(app(TrustedDeviceService::class)->revokerCanRevoke($actor, $target))->toBeFalse();
});

it('medium can revoke low but not medium or high', function () {
    $user   = $this->createUser();
    $actor  = makeTrustedDevice($user, 'low', daysOld: 65);   // resolves to medium
    $low    = makeTrustedDevice($user, 'low', daysOld: 20);
    $medium = makeTrustedDevice($user, 'low', daysOld: 70);
    $high   = makeTrustedDevice($user, 'low', daysOld: 100);

    $svc = app(TrustedDeviceService::class);
    expect($svc->revokerCanRevoke($actor, $low))->toBeTrue()
        ->and($svc->revokerCanRevoke($actor, $medium))->toBeFalse()
        ->and($svc->revokerCanRevoke($actor, $high))->toBeFalse();
});

it('high can revoke low + medium + high', function () {
    $user   = $this->createUser();
    $actor  = makeTrustedDevice($user, 'low', daysOld: 100);  // resolves to high
    $low    = makeTrustedDevice($user, 'low', daysOld: 20);
    $medium = makeTrustedDevice($user, 'low', daysOld: 70);
    $high   = makeTrustedDevice($user, 'low', daysOld: 95);

    $svc = app(TrustedDeviceService::class);
    expect($svc->revokerCanRevoke($actor, $low))->toBeTrue()
        ->and($svc->revokerCanRevoke($actor, $medium))->toBeTrue()
        ->and($svc->revokerCanRevoke($actor, $high))->toBeTrue();
});

it('any trusted device can revoke all', function () {
    $user  = $this->createUser();
    $low   = makeTrustedDevice($user, 'low', daysOld: 20);
    $high  = makeTrustedDevice($user, 'low', daysOld: 100);

    $svc = app(TrustedDeviceService::class);
    expect($svc->revokerCanRevokeAll($low))->toBeTrue()
        ->and($svc->revokerCanRevokeAll($high))->toBeTrue();
});

it('untrusted device cannot revoke any other device or revoke-all', function () {
    $user        = $this->createUser();
    $untrusted   = AuthTrustedDevice::create([
        'user_id'          => $user->getKey(),
        'fingerprint_hash' => str_repeat('z', 32),
        'level'            => 'low',
        'first_seen_at'    => now(),
        'last_seen_at'     => now(),
        'trusted_at'       => null,   // never trusted
    ]);
    $target = makeTrustedDevice($user, 'low', daysOld: 20);

    $svc = app(TrustedDeviceService::class);
    expect($svc->revokerCanRevoke($untrusted, $target))->toBeFalse()
        ->and($svc->revokerCanRevokeAll($untrusted))->toBeFalse();
});

it('re-trusting a previously revoked device clears revoked_at', function () {
    $user   = $this->createUser();
    $device = AuthTrustedDevice::create([
        'user_id'          => $user->getKey(),
        'fingerprint_hash' => str_repeat('r', 32),
        'level'            => 'low',
        'first_seen_at'    => now()->subDays(30),
        'last_seen_at'     => now()->subDays(30),
        'trusted_at'       => now()->subDays(30),
        'revoked_at'       => now()->subDay(),
    ]);

    $request = \Illuminate\Http\Request::create('/');
    $request->merge(['_device' => [
        'fingerprint_hash' => str_repeat('r', 32),
        'platform'         => 'web',
        'ip_address'       => '127.0.0.1',
    ]]);

    app(TrustedDeviceService::class)->trustCurrent($user, $request);

    expect($device->fresh()->revoked_at)->toBeNull()
        ->and($device->fresh()->trusted_at)->not->toBeNull();
});

// ---- helpers ----

function makeTrustedDevice($user, string $storedLevel, int $daysOld): AuthTrustedDevice
{
    return AuthTrustedDevice::create([
        'user_id'          => $user->getKey(),
        'fingerprint_hash' => bin2hex(random_bytes(16)),
        'level'            => $storedLevel,
        'first_seen_at'    => now()->subDays($daysOld),
        'last_seen_at'     => now(),
        'trusted_at'       => now()->subDays($daysOld),
    ]);
}
