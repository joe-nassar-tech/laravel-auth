<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Joe404\LaravelAuth\Models\AuthTrustedDevice;
use Joe404\LaravelAuth\Services\TrustedDeviceService;

beforeEach(function (): void {
    Notification::fake();
    \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'user', 'guard_name' => 'web']);
    config([
        'auth_system.two_factor.enabled'      => true,
        'auth_system.trusted_devices.enabled' => true,
    ]);
});

it('records the configured initial trust level for the registration device', function (): void {
    config(['auth_system.trusted_devices.registration_device_level' => 'medium']);

    $user = $this->createUser(['email' => 'rd@example.com']);

    $request = Request::create('/x', 'POST');
    $request->merge(['_device' => ['fingerprint_hash' => str_repeat('d', 64)]]);

    $device = app(TrustedDeviceService::class)->autoTrustRegistrationDevice($user, $request);

    expect($device)->not->toBeNull();
    expect($device->level)->toBe('medium');
});

it('a freshly-registered device does NOT bypass 2FA on the next login under default config', function (): void {
    // Default config: bypass_2fa_min_level = 'high', thresholds low = 15 days.
    // A device trusted "just now" resolves to 'low', so even with the server
    // device token present it must still face the 2FA challenge — registration
    // never grants an instant bypass under the secure default.
    $user = $this->createUser(['email' => 'freshreg@example.com', 'password' => bcrypt('password')]);
    enrollTotp($user);

    $fingerprint = str_repeat('e', 64);
    $plainSecret = bin2hex(random_bytes(32));

    AuthTrustedDevice::create([
        'user_id'          => $user->getKey(),
        'fingerprint_hash' => $fingerprint,
        'secret_hash'      => hash('sha256', $plainSecret),
        'level'            => 'high',  // stored high…
        'first_seen_at'    => now(),
        'last_seen_at'     => now(),
        'trusted_at'       => now(),    // …but trusted just now → resolves to low
    ]);

    $this->withHeader('X-Browser-Fingerprint', $fingerprint)
        ->withHeader('X-Trusted-Device-Token', $plainSecret)
        ->postJson('/auth/login', ['email' => 'freshreg@example.com', 'password' => 'password'])
        ->assertOk()
        ->assertJsonPath('data.requires_2fa', true);
});
