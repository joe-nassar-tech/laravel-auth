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

        [$subject, $view] = match ($this->type) {
            'magic_link_reset'  => ['Reset Your Password',        'laravel-auth::emails.magic-link-reset'],
            default             => ['Verify Your Email Address',   'laravel-auth::emails.magic-link-verify'],
        };

        return (new MailMessage())
            ->subject($subject)
            ->markdown($view, [
                'url'       => $this->url,
                'expiresIn' => $expiresIn,
            ]);
    }
}
