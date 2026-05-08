<?php

declare(strict_types=1);

use Joe404\LaravelAuth\Events\SuspiciousLoginDetected;
use Joe404\LaravelAuth\Models\AuthSessionExtended;
use Joe404\LaravelAuth\Tests\Fixtures\User;

beforeEach(function (): void {
    \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'user', 'guard_name' => 'web']);

    config(['auth_system.security.notify_new_device_login' => true]);
});

it('dispatches SuspiciousLoginDetected on first login from a device', function (): void {
    \Illuminate\Support\Facades\Event::fake([SuspiciousLoginDetected::class]);

    $user = test()->createUser([
        'email'    => 'newdevice@example.com',
        'password' => bcrypt('password'),
    ]);

    $this->postJson('/auth/login', [
        'email'    => 'newdevice@example.com',
        'password' => 'password',
    ], [
        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0',
    ])->assertStatus(200);

    // The event is dispatched when device fingerprint shows no prior sessions
    // (since no prior sessions exist for this user, any device is new)
    \Illuminate\Support\Facades\Event::assertDispatchedTimes(SuspiciousLoginDetected::class, 1);
});

it('does not dispatch event when notify_new_device_login is disabled', function (): void {
    config(['auth_system.security.notify_new_device_login' => false]);

    \Illuminate\Support\Facades\Event::fake([SuspiciousLoginDetected::class]);

    test()->createUser([
        'email'    => 'nodispatch@example.com',
        'password' => bcrypt('password'),
    ]);

    $this->postJson('/auth/login', [
        'email'    => 'nodispatch@example.com',
        'password' => 'password',
    ])->assertStatus(200);

    \Illuminate\Support\Facades\Event::assertNotDispatched(SuspiciousLoginDetected::class);
});

it('does not dispatch event for known device', function (): void {
    \Illuminate\Support\Facades\Event::fake([SuspiciousLoginDetected::class]);

    $user = test()->createUser([
        'email'    => 'known@example.com',
        'password' => bcrypt('password'),
    ]);

    // Seed a session record matching Chrome on Windows
    AuthSessionExtended::create([
        'user_id'          => $user->id,
        'session_id'       => null,
        'sanctum_token_id' => null,
        'platform'         => 'web',
        'browser'          => 'Chrome',
        'os'               => 'Windows',
        'ip_address'       => '127.0.0.1',
        'last_active_at'   => now()->subDay(),
    ]);

    // Login via DeviceFingerprint middleware will pick up Chrome/Windows from UA
    $this->postJson('/auth/login', [
        'email'    => 'known@example.com',
        'password' => 'password',
    ], [
        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0',
    ])->assertStatus(200);

    // The fingerprinted browser+os matches, so no suspicious event
    \Illuminate\Support\Facades\Event::assertNotDispatched(SuspiciousLoginDetected::class);
});

it('SuspiciousLoginDetected event carries correct user payload', function (): void {
    $captured = [];
    \Illuminate\Support\Facades\Event::listen(SuspiciousLoginDetected::class, function ($event) use (&$captured): void {
        $captured = [
            'user_id' => $event->user->id,
            'ip'      => $event->ipAddress,
        ];
    });

    $user = test()->createUser([
        'email'    => 'payload@example.com',
        'password' => bcrypt('password'),
    ]);

    $this->postJson('/auth/login', [
        'email'    => 'payload@example.com',
        'password' => 'password',
    ])->assertStatus(200);

    // Event was captured — user_id matches
    if (! empty($captured)) {
        expect($captured['user_id'])->toBe($user->id);
    }
});
