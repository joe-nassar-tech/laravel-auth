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

/**
 * Enroll a verified TOTP method for a user and return the plaintext secret.
 *
 * Defined here (not in an individual test file) so it is available to every
 * test, including separate worker processes under `pest --parallel`. Mirrors
 * TwoFactorService enrollment: encrypted secret, verified, default method.
 */
function enrollTotp($user): string
{
    $secret = (new \Joe404\LaravelAuth\Services\TotpService())->generateSecret();

    \Joe404\LaravelAuth\Models\AuthTwoFactorMethod::create([
        'user_id'          => $user->getKey(),
        'type'             => 'totp',
        'secret_encrypted' => \Illuminate\Support\Facades\Crypt::encryptString($secret),
        'is_default'       => true,
        'verified_at'      => now(),
    ]);

    return $secret;
}
