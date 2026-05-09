<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OtpCodeNotification extends Notification
{
    public function __construct(
        private readonly string $code,
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
            'password_reset' => ['Your Password Reset Code',   'laravel-auth::emails.otp-reset'],
            default          => ['Your Verification Code',     'laravel-auth::emails.otp-verify'],
        };

        return (new MailMessage())
            ->subject($subject)
            ->markdown($view, [
                'code'      => $this->code,
                'expiresIn' => $expiresIn,
            ]);
    }
}
