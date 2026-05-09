<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CombinedOtpMagicLinkNotification extends Notification
{
    public function __construct(
        private readonly string $code,
        private readonly string $url,
        private readonly string $type,
        private readonly array $context = [],
    ) {}

    /**
     * @return array<string>
     */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $expiresIn = $this->context['expires_in'] ?? 10;

        [$subject, $view] = match ($this->type) {
            'password_reset' => ['Reset Your Password',        'laravel-auth::emails.otp-reset-combined'],
            default          => ['Verify Your Email Address',  'laravel-auth::emails.otp-verify-combined'],
        };

        return (new MailMessage())
            ->subject($subject)
            ->markdown($view, [
                'code'      => $this->code,
                'url'       => $this->url,
                'expiresIn' => $expiresIn,
            ]);
    }
}
