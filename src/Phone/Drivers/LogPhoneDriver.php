<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Phone\Drivers;

use Illuminate\Support\Facades\Log;
use Joe404\LaravelAuth\Contracts\PhoneDriverContract;
use Joe404\LaravelAuth\Exceptions\PhoneVerificationException;

class LogPhoneDriver implements PhoneDriverContract
{
    public function send(string $phone, string $code, string $channel, array $context = []): void
    {
        // Hard stop outside local/testing. This driver writes the plaintext
        // OTP to the application log — acceptable for local development, never
        // for an internet-facing environment. Failing loud here prevents a
        // misconfiguration (e.g. log driver left on in staging) from silently
        // leaking live authentication codes into log aggregators.
        $env = (string) app()->environment();
        if (! in_array($env, ['local', 'testing'], true)) {
            throw new PhoneVerificationException(
                "The 'log' phone driver only runs in local/testing (current environment: {$env}). "
                . 'Configure a real provider (infobip, twilio, messagecentral, …) for this channel.',
                'phone_log_driver_blocked',
            );
        }

        Log::info('[laravel-auth] Phone OTP (log driver)', [
            'phone'   => $phone,
            'code'    => $code,
            'channel' => $channel,
            'context' => $context,
        ]);
    }

    public function supports(): array
    {
        return ['sms', 'voice', 'whatsapp'];
    }

    public function name(): string
    {
        return 'log';
    }
}
