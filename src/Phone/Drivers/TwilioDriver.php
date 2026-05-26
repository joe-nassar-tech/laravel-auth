<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Phone\Drivers;

use Illuminate\Support\Facades\Http;
use Joe404\LaravelAuth\Contracts\PhoneDriverContract;
use Joe404\LaravelAuth\Exceptions\PhoneVerificationException;

class TwilioDriver implements PhoneDriverContract
{
    /** @param array<string,mixed> $config */
    public function __construct(private readonly array $config) {}

    public function send(string $phone, string $code, string $channel, array $context = []): void
    {
        $sid   = (string) ($this->config['sid'] ?? '');
        $token = (string) ($this->config['token'] ?? '');
        $from  = (string) ($this->config['from'] ?? '');

        if ($sid === '' || $token === '' || $from === '') {
            throw new PhoneVerificationException('Twilio credentials are not configured.', 'phone_driver_misconfigured');
        }

        if (! in_array($channel, $this->supports(), true)) {
            throw new PhoneVerificationException("Channel {$channel} not supported by twilio driver.", 'phone_channel_unsupported');
        }

        $body = "Your verification code is: {$code}";

        if ($channel === 'voice') {
            $endpoint = "https://api.twilio.com/2010-04-01/Accounts/{$sid}/Calls.json";
            $payload  = [
                'From' => $from,
                'To'   => $phone,
                'Twiml' => "<Response><Say>Your code is {$code}. I repeat, {$code}.</Say></Response>",
            ];
        } else {
            $endpoint = "https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json";
            $to       = $channel === 'whatsapp' ? "whatsapp:{$phone}" : $phone;
            $payload  = [
                'From' => $channel === 'whatsapp' ? "whatsapp:{$from}" : $from,
                'To'   => $to,
                'Body' => $body,
            ];
        }

        $response = Http::withBasicAuth($sid, $token)
            ->timeout(10)
            ->asForm()
            ->post($endpoint, $payload);

        if (! $response->successful()) {
            throw new PhoneVerificationException(
                "Twilio {$channel} send failed: HTTP {$response->status()}",
                'phone_send_failed',
            );
        }
    }

    public function supports(): array
    {
        return ['sms', 'voice', 'whatsapp'];
    }

    public function name(): string
    {
        return 'twilio';
    }
}
