<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Contracts;

/**
 * Optional extension for channels that can deliver a combined OTP + magic-link
 * message in a single send (e.g. one email containing both).
 *
 * Channels that do NOT implement this interface continue to work — OtpService
 * falls back to calling send() twice (once for the OTP, once for the link)
 * when AUTH_VERIFICATION_METHOD=both is configured.
 *
 * Custom drivers written before this interface existed are therefore
 * unaffected; implement this interface only when you want to merge both
 * pieces of information into one delivery.
 */
interface CombinedOtpChannelContract extends OtpChannelContract
{
    public function sendCombined(string $recipient, string $code, string $url, string $type, array $context = []): void;
}
