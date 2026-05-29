<?php

declare(strict_types=1);

use Joe404\LaravelAuth\Services\AuthService;

beforeEach(function (): void {
    \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'user', 'guard_name' => 'web']);
});

it('strips the configured hidden_user_fields from serialized users', function (): void {
    // 'name' is a real, populated column — use it to prove the config drives the
    // strip (a non-existent column would pass trivially).
    config(['auth_system.response.hidden_user_fields' => ['password', 'remember_token', 'name']]);

    $service = app(AuthService::class);
    $method  = new ReflectionMethod($service, 'safeUserArray');
    $method->setAccessible(true);

    $out = $method->invoke($service, $this->createUser(['email' => 'hf@example.com']));

    expect($out)->toHaveKey('email');
    expect($out)->not->toHaveKey('password');
    expect($out)->not->toHaveKey('remember_token');
    expect($out)->not->toHaveKey('name'); // custom field, stripped via config
});

it('defaults to stripping only password and remember_token', function (): void {
    $service = app(AuthService::class);
    $method  = new ReflectionMethod($service, 'safeUserArray');
    $method->setAccessible(true);

    $out = $method->invoke($service, $this->createUser(['email' => 'hf2@example.com']));

    expect($out)->not->toHaveKey('password');
    expect($out)->not->toHaveKey('remember_token');
    expect($out)->toHaveKey('name'); // not stripped unless configured
});
