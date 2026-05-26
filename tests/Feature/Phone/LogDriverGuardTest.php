<?php

declare(strict_types=1);

use Joe404\LaravelAuth\Exceptions\PhoneVerificationException;
use Joe404\LaravelAuth\Phone\Drivers\LogPhoneDriver;

it('allows the log driver to send in the testing environment', function () {
    \Illuminate\Support\Facades\Log::spy();

    expect(app()->environment())->toBe('testing');

    (new LogPhoneDriver())->send('+14155550100', '123456', 'sms');

    \Illuminate\Support\Facades\Log::shouldHaveReceived('info')->once();
});

it('refuses to send from the log driver outside local/testing', function () {
    // Force a production-like environment for this assertion.
    app()->detectEnvironment(fn () => 'production');

    expect(fn () => (new LogPhoneDriver())->send('+14155550100', '123456', 'sms'))
        ->toThrow(PhoneVerificationException::class);

    // restore
    app()->detectEnvironment(fn () => 'testing');
});
