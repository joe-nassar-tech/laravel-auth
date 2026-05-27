<?php

declare(strict_types=1);

use Joe404\LaravelAuth\AuthServiceProvider;
use Joe404\LaravelAuth\Services\AuthService;

it('fails boot validation when APP_KEY is empty (OTP / backup-code pepper)', function (): void {
    config(['app.key' => '']);

    $provider = new AuthServiceProvider($this->app);
    $validate = new ReflectionMethod($provider, 'validateConfig');
    $validate->setAccessible(true);

    expect(fn () => $validate->invoke($provider))->toThrow(\InvalidArgumentException::class);
});

it('strips the package gating columns from registration extra fields', function (): void {
    $service = app(AuthService::class);
    $strip   = new ReflectionMethod($service, 'stripPrivilegedFields');
    $strip->setAccessible(true);

    $out = $strip->invoke($service, [
        'username'            => 'legit',
        'account_status'      => 'active',
        'status_expires_at'   => null,
        'phone_verified_at'   => now(),
        'two_factor_required' => true,
        'is_admin'            => true,
    ]);

    expect($out)->toHaveKey('username');
    expect($out)->not->toHaveKey('account_status');
    expect($out)->not->toHaveKey('status_expires_at');
    expect($out)->not->toHaveKey('phone_verified_at');
    expect($out)->not->toHaveKey('two_factor_required');
    expect($out)->not->toHaveKey('is_admin');
});
