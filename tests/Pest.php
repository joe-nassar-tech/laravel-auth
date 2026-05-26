<?php

declare(strict_types=1);

uses(Joe404\LaravelAuth\Tests\TestCase::class)->in('Feature', 'Unit');

/**
 * Hash an OTP / magic-link token the same way OtpService stores it (v2.6.1+):
 * HMAC-SHA256 with the app key as pepper. Tests that seed auth_otp_codes rows
 * directly must use this so the service's lookup (also HMAC) matches.
 */
function authOtpHash(string $value): string
{
    $key = (string) config('app.key', '');

    if (str_starts_with($key, 'base64:')) {
        $key = base64_decode(substr($key, 7), true) ?: $key;
    }

    return hash_hmac('sha256', $value, $key);
}
