<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

class AccountDeletedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly Carbon $scheduledPurgeAt,
        public readonly int $graceDays,
    ) {}

    /** @return array<int, string> */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject('Your account has been scheduled for deletion')
            ->view('laravel-auth::emails.account-deleted', [
                'notifiable'        => $notifiable,
                'scheduledPurgeAt'  => $this->scheduledPurgeAt,
                'graceDays'         => $this->graceDays,
            ]);
    }
}
