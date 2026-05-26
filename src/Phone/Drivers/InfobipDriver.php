<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Phone\Drivers;

use Illuminate\Support\Facades\Http;
use Joe404\LaravelAuth\Contracts\PhoneDriverContract;
use Joe404\LaravelAuth\Exceptions\PhoneVerificationException;

class InfobipDriver implements PhoneDriverContract
{
    /** @param array<string,mixed> $config */
    public function __construct(private readonly array $config) {}

    public function send(string $phone, string $code, string $channel, array $context = []): void
    {
        $apiKey  = (string) ($this->config['api_key'] ?? '');
        $baseUrl = rtrim((string) ($this->config['base_url'] ?? 'https://api.infobip.com'), '/');
        $sender  = (string) ($this->config['sender'] ?? 'InfoSMS');

        if ($apiKey === '') {
            throw new PhoneVerificationException('Infobip api_key is not configured.', 'phone_driver_misconfigured');
        }

        $endpoint = match ($channel) {
            'sms'      => '/sms/2/text/advanced',
            'voice'    => '/tts/3/advanced',
            'whatsapp' => '/whatsapp/1/message/text',
            default    => throw new PhoneVerificationException("Channel {$channel} not supported by infobip driver.", 'phone_channel_unsupported'),
        };

        $payload = match ($channel) {
            'sms', 'voice' => [
                'messages' => [[
                    'from'         => $sender,
                    'destinations' => [['to' => $phone]],
                    'text'         => $this->renderText($code, $channel, $context),
                ]],
            ],
            'whatsapp' => [
                'from'    => $sender,
                'to'      => $phone,
                'content' => ['text' => $this->renderText($code, $channel, $context)],
            ],
        };

        $response = Http::baseUrl($baseUrl)
            ->withHeaders([
                'Authorization' => "App {$apiKey}",
                'Accept'        => 'application/json',
            ])
            ->timeout(10)
            ->post($endpoint, $payload);

        if (! $response->successful()) {
            throw new PhoneVerificationException(
                "Infobip {$channel} send failed: HTTP {$response->status()}",
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
        return 'infobip';
    }

    /** @param array<string,mixed> $context */
    private function renderText(string $code, string $channel, array $context): string
    {
        $template = (string) ($context['template'] ?? 'Your verification code is: {code}. It expires in {minutes} minutes.');

        return strtr($template, [
            '{code}'    => $code,
            '{minutes}' => (string) ($context['minutes'] ?? 5),
        ]);
    }
}
