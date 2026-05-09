<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ExistingAccountNotification extends Notification
{
    use Queueable;

    /** @return array<int, string> */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject('You already have an account')
            ->greeting('Hello,')
            ->line('We received a sign-up attempt for your email, but you already have an account with us.')
            ->line('If this was you, please log in instead. If you forgot your password, use the password reset link below.')
            ->action('Reset password', url('/auth/password/forgot'))
            ->line('If this was not you, you can safely ignore this email — no action will be taken on your account.');
    }
}
