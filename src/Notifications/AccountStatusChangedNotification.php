<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AccountStatusChangedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $previousStatus,
        public readonly string $newStatus,
        public readonly ?string $reason = null,
    ) {}

    /** @return array<int, string> */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject('Your account status has changed')
            ->view('laravel-auth::emails.account-status-changed', [
                'notifiable'     => $notifiable,
                'previousStatus' => $this->previousStatus,
                'newStatus'      => $this->newStatus,
                'reason'         => $this->reason,
            ]);
    }
}
