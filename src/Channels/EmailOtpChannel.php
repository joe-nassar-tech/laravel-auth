<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Channels;

use Illuminate\Support\Facades\Notification;
use Joe404\LaravelAuth\Contracts\OtpChannelContract;
use Joe404\LaravelAuth\Notifications\MagicLinkNotification;
use Joe404\LaravelAuth\Notifications\OtpCodeNotification;

class EmailOtpChannel implements OtpChannelContract
{
    public function send(string $recipient, string $code, string $type, array $context = []): void
    {
        $notification = match ($type) {
            'magic_link_verify', 'magic_link_reset' => new MagicLinkNotification($code, $type, $context),
            default                                  => new OtpCodeNotification($code, $type, $context),
        };

        Notification::route('mail', $recipient)->notify($notification);
    }
}
