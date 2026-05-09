<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

class SocialLinkConfirmationNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $provider,
        private readonly string $token,
    ) {}

    /** @return array<int, string> */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $url = URL::temporarySignedRoute(
            'auth.social.link.confirm',
            now()->addMinutes(15),
            ['provider' => $this->provider, 'token' => $this->token],
        );

        return (new MailMessage())
            ->subject('Confirm linking your ' . ucfirst($this->provider) . ' account')
            ->greeting('Hello,')
            ->line('Someone (hopefully you) just tried to sign in to your account using ' . ucfirst($this->provider) . '.')
            ->line('Click the button below to link your ' . ucfirst($this->provider) . ' account and finish signing in.')
            ->action('Link account and sign in', $url)
            ->line('If this was not you, ignore this email — your account remains untouched. Consider changing your password as a precaution.');
    }
}
