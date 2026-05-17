<?php

declare(strict_types=1);

use Joe404\LaravelAuth\Services\UniqueColumnResolver;

it('discovers single-column unique indexes on the users table in auto mode', function (): void {
    config()->set('auth_system.account.deletion.unique_columns', 'auto');
    config()->set('auth_system.account.deletion.unique_exclude', ['id']);

    $resolver = new UniqueColumnResolver();
    $columns  = $resolver->resolve('users');

    // Fixture users table has unique on email and referral_code.
    expect($columns)->toContain('email');
    expect($columns)->not->toContain('id');
});

it('honors an explicit array override', function (): void {
    config()->set('auth_system.account.deletion.unique_columns', ['email']);

    $resolver = new UniqueColumnResolver();
    expect($resolver->resolve('users'))->toBe(['email']);
});

it('respects the exclude list', function (): void {
    config()->set('auth_system.account.deletion.unique_columns', ['email', 'id']);
    config()->set('auth_system.account.deletion.unique_exclude', ['id']);

    $resolver = new UniqueColumnResolver();
    expect($resolver->resolve('users'))->toBe(['email']);
});
