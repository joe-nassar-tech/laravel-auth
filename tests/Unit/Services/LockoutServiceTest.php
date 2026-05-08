<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Joe404\LaravelAuth\Exceptions\AuthException;
use Joe404\LaravelAuth\Services\LockoutService;

beforeEach(function (): void {
    Cache::flush();
    config([
        'auth_system.security.lockout.enabled'       => true,
        'auth_system.security.lockout.max_attempts'  => 3,
        'auth_system.security.lockout.decay_minutes' => 15,
    ]);
});

it('is not locked out by default', function (): void {
    $service = app(LockoutService::class);

    expect($service->isLockedOut('test@example.com'))->toBeFalse();
});

it('records failures and locks after reaching max attempts', function (): void {
    $service = app(LockoutService::class);
    $email   = 'lock@example.com';

    $service->recordFailure($email);
    expect($service->isLockedOut($email))->toBeFalse();

    $service->recordFailure($email);
    expect($service->isLockedOut($email))->toBeFalse();

    $service->recordFailure($email); // 3rd = threshold
    expect($service->isLockedOut($email))->toBeTrue();
});

it('throws AuthException when locked out', function (): void {
    $service = app(LockoutService::class);
    $email   = 'throw@example.com';

    // Trigger lockout
    $service->recordFailure($email);
    $service->recordFailure($email);
    $service->recordFailure($email);

    expect(fn () => $service->throwIfLockedOut($email))->toThrow(AuthException::class);
});

it('clears lockout and counter', function (): void {
    $service = app(LockoutService::class);
    $email   = 'clear@example.com';

    $service->recordFailure($email);
    $service->recordFailure($email);
    $service->recordFailure($email);

    expect($service->isLockedOut($email))->toBeTrue();

    $service->clear($email);

    expect($service->isLockedOut($email))->toBeFalse();
    expect(fn () => $service->throwIfLockedOut($email))->not->toThrow(AuthException::class);
});

it('does not lock when lockout is disabled', function (): void {
    config(['auth_system.security.lockout.enabled' => false]);

    $service = app(LockoutService::class);
    $email   = 'disabled@example.com';

    for ($i = 0; $i < 20; $i++) {
        $service->recordFailure($email);
    }

    expect($service->isLockedOut($email))->toBeFalse();
});

it('lockout exception message includes decay minutes', function (): void {
    $service = app(LockoutService::class);
    $email   = 'msg@example.com';

    $service->recordFailure($email);
    $service->recordFailure($email);
    $service->recordFailure($email);

    try {
        $service->throwIfLockedOut($email);
        expect(false)->toBeTrue('Expected AuthException was not thrown');
    } catch (AuthException $e) {
        expect($e->getMessage())->toContain('15');
    }
});
