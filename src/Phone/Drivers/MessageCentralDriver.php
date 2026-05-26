<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Phone\Drivers;

use Illuminate\Support\Facades\Http;
use Joe404\LaravelAuth\Contracts\PhoneDriverContract;
use Joe404\LaravelAuth\Exceptions\PhoneVerificationException;

class MessageCentralDriver implements PhoneDriverContract
{
    /** @param array<string,mixed> $config */
    public function __construct(private readonly array $config) {}

    public function send(string $phone, string $code, string $channel, array $context = []): void
    {
        $customerId = (string) ($this->config['customer_id'] ?? '');
        $password   = (string) ($this->config['password'] ?? '');
        $baseUrl    = rtrim((string) ($this->config['base_url'] ?? 'https://cpaas.messagecentral.com'), '/');

        if ($customerId === '' || $password === '') {
            throw new PhoneVerificationException('MessageCentral credentials are not configured.', 'phone_driver_misconfigured');
        }

        if (! in_array($channel, $this->supports(), true)) {
            throw new PhoneVerificationException("Channel {$channel} not supported by messagecentral driver.", 'phone_channel_unsupported');
        }

        $response = Http::baseUrl($baseUrl)
            ->withHeaders([
                'authToken'    => $password,
                'customerId'   => $customerId,
                'Content-Type' => 'application/json',
            ])
            ->timeout(10)
            ->post('/verification/v3/send', [
                'mobileNumber' => ltrim($phone, '+'),
                'flowType'     => strtoupper($channel),
                'otpLength'    => strlen($code),
                'message'      => "Your code: {$code}",
            ]);

        if (! $response->successful()) {
            throw new PhoneVerificationException(
                "MessageCentral {$channel} send failed: HTTP {$response->status()}",
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
        return 'messagecentral';
    }
}
