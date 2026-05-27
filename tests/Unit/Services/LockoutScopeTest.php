<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Joe404\LaravelAuth\Services\LockoutService;

beforeEach(function (): void {
    Cache::flush();
    config([
        'auth_system.security.lockout.enabled'      => true,
        'auth_system.security.lockout.max_attempts' => 3,
    ]);
});

it('with email_and_ip scope, a lock from one IP does not lock the email from another IP', function (): void {
    config(['auth_system.security.lockout.scope' => 'email_and_ip']);

    $service = app(LockoutService::class);
    $email   = 'victim@example.com';

    // Attacker hammering from one IP trips the lock for that (email, IP) pair.
    $service->recordFailure($email, '10.0.0.1');
    $service->recordFailure($email, '10.0.0.1');
    $service->recordFailure($email, '10.0.0.1');

    expect($service->isLockedOut($email, '10.0.0.1'))->toBeTrue();

    // The real owner, on a different IP, is NOT locked out — no targeted DoS.
    expect($service->isLockedOut($email, '203.0.113.9'))->toBeFalse();
});

it('default email scope still locks the address regardless of IP (back-compat)', function (): void {
    // No scope configured → defaults to 'email'.
    $service = app(LockoutService::class);
    $email   = 'classic@example.com';

    $service->recordFailure($email, '10.0.0.1');
    $service->recordFailure($email, '10.0.0.2');
    $service->recordFailure($email, '10.0.0.3');

    // Locked no matter which IP asks.
    expect($service->isLockedOut($email, '198.51.100.7'))->toBeTrue();
});
