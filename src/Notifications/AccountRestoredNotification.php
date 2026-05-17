<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AccountRestoredNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $trigger = 'login',
    ) {}

    /** @return array<int, string> */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject('Your account has been restored')
            ->view('laravel-auth::emails.account-restored', [
                'notifiable' => $notifiable,
                'trigger'    => $this->trigger,
            ]);
    }
}
