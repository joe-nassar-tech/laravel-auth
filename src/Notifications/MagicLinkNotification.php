<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MagicLinkNotification extends Notification
{
    public function __construct(
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
        $expiresIn = $this->context['expires_in'] ?? 30;

        [$subject, $actionText] = match ($this->type) {
            'magic_link_reset'  => ['Reset Your Password', 'Reset Password'],
            default             => ['Verify Your Email Address', 'Verify Email'],
        };

        return (new MailMessage())
            ->subject($subject)
            ->greeting('Hello!')
            ->line('Click the button below to ' . strtolower($actionText) . '.')
            ->action($actionText, $this->url)
            ->line("This link will expire in {$expiresIn} minutes.")
            ->line('If you did not request this, please ignore this email.');
    }
}
