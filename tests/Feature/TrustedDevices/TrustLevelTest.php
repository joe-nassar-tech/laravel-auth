<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Joe404\LaravelAuth\Models\AuthTrustedDevice;
use Joe404\LaravelAuth\Services\TrustLevelResolver;

it('resolves time-based level progression', function () {
    config([
        'auth_system.trusted_devices.level_assignment' => 'time',
        'auth_system.trusted_devices.thresholds_days'  => [
            'low'    => 15,
            'medium' => 60,
            'high'   => 90,
        ],
    ]);

    $user = $this->createUser();

    $cases = [
        ['days' => 5,  'expected' => 'low'],
        ['days' => 20, 'expected' => 'low'],
        ['days' => 65, 'expected' => 'medium'],
        ['days' => 95, 'expected' => 'high'],
    ];

    foreach ($cases as $case) {
        $device = AuthTrustedDevice::create([
            'user_id'          => $user->getKey(),
            'fingerprint_hash' => str_repeat('a', 32) . $case['days'],
            'level'            => 'low',
            'first_seen_at'    => now()->subDays($case['days']),
            'last_seen_at'     => now(),
            'trusted_at'       => now()->subDays($case['days']),
        ]);

        expect(app(TrustLevelResolver::class)->resolve($device))->toBe($case['expected']);
    }
});

it('admin-granted high trust short-circuits time-based resolution', function () {
    config(['auth_system.trusted_devices.level_assignment' => 'time']);

    $user = $this->createUser();

    $device = AuthTrustedDevice::create([
        'user_id'          => $user->getKey(),
        'fingerprint_hash' => str_repeat('b', 32),
        'level'            => 'high',
        'admin_granted'    => true,
        'first_seen_at'    => now(),
        'last_seen_at'     => now(),
        'trusted_at'       => now()->subDay(),  // brand new — would be "low" by time
    ]);

    expect(app(TrustLevelResolver::class)->resolve($device))->toBe('high');
});

it('treats revoked devices as untrusted regardless of age', function () {
    $user = $this->createUser();

    $device = AuthTrustedDevice::create([
        'user_id'          => $user->getKey(),
        'fingerprint_hash' => str_repeat('c', 32),
        'level'            => 'high',
        'first_seen_at'    => now()->subDays(120),
        'last_seen_at'     => now()->subDays(120),
        'trusted_at'       => now()->subDays(120),
        'revoked_at'       => now()->subDay(),
    ]);

    expect(app(TrustLevelResolver::class)->resolve($device))->toBe('untrusted');
});
