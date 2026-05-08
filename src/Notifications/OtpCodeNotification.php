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

        $subject = match ($this->type) {
            'password_reset' => 'Your Password Reset Code',
            default          => 'Your Verification Code',
        };

        return (new MailMessage())
            ->subject($subject)
            ->greeting('Hello!')
            ->line('Use the following OTP code to complete your request:')
            ->line("**{$this->code}**")
            ->line("This code will expire in {$expiresIn} minutes.")
            ->line('If you did not request this, please ignore this email.');
    }
}
