<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NewDeviceLoginNotification extends Notification
{
    public function __construct(
        private readonly string $ipAddress,
        private readonly ?string $browser,
        private readonly ?string $os,
        private readonly ?string $city,
        private readonly ?string $country,
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
        $location = collect([$this->city, $this->country])->filter()->implode(', ');
        $device   = collect([$this->browser, $this->os])->filter()->implode(' on ');

        $message = (new MailMessage())
            ->subject('New device login detected')
            ->greeting('Security alert')
            ->line('A sign-in was detected from a device we have not seen before.')
            ->line("**IP address:** {$this->ipAddress}");

        if ($device !== '') {
            $message->line("**Device:** {$device}");
        }

        if ($location !== '') {
            $message->line("**Location:** {$location}");
        }

        return $message
            ->line('If this was you, no action is needed.')
            ->line('If you do not recognise this activity, please change your password immediately.');
    }
}
