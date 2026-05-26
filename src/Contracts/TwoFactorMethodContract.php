<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Contracts;

use Joe404\LaravelAuth\Models\AuthTwoFactorMethod;

interface TwoFactorMethodContract
{
    /**
     * Method type key — must match one of the enum values stored on
     * auth_two_factor_methods.type (e.g. "totp", "email", "sms").
     */
    public function type(): string;

    /**
     * Start enrollment. For TOTP this generates the secret + returns it (with
     * an otpauth URI for QR rendering). For email/SMS this sends the OTP and
     * returns no secret. The returned array is exposed to the client.
     *
     * @return array<string,mixed>
     */
    public function startEnrollment(int $userId): array;

    /**
     * Verify the enrollment code and persist the AuthTwoFactorMethod row as
     * `verified_at = now()`. Throws on invalid/expired code.
     */
    public function verifyEnrollment(int $userId, string $code): AuthTwoFactorMethod;

    /**
     * Issue a fresh challenge code for an already-enrolled method (login flow).
     * TOTP returns a no-op (user reads code from app); email/SMS push the code.
     */
    public function issueChallenge(AuthTwoFactorMethod $method): void;

    /**
     * Verify a code against an enrolled method. Returns true on success.
     */
    public function verifyCode(AuthTwoFactorMethod $method, string $code): bool;
}
