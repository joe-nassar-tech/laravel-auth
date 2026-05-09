<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Channels;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Joe404\LaravelAuth\Contracts\CombinedOtpChannelContract;
use Joe404\LaravelAuth\Notifications\CombinedOtpMagicLinkNotification;
use Joe404\LaravelAuth\Notifications\MagicLinkNotification;
use Joe404\LaravelAuth\Notifications\OtpCodeNotification;

class EmailOtpChannel implements CombinedOtpChannelContract
{
    public function send(string $recipient, string $code, string $type, array $context = []): void
    {
        NotificationFacade::route('mail', $recipient)->notify(
            $this->resolveNotification($code, $type, $context),
        );
    }

    public function sendCombined(string $recipient, string $code, string $url, string $type, array $context = []): void
    {
        $configKey = $type === 'password_reset'
            ? 'auth_system.mail.otp_reset_combined_notification'
            : 'auth_system.mail.otp_verify_combined_notification';

        $customClass = config($configKey);

        $notification = ($customClass !== null && class_exists((string) $customClass))
            ? new $customClass($code, $url, $type, $context)
            : new CombinedOtpMagicLinkNotification($code, $url, $type, $context);

        NotificationFacade::route('mail', $recipient)->notify($notification);
    }

    private function resolveNotification(string $code, string $type, array $context): Notification
    {
        $configKey = match ($type) {
            'magic_link_verify' => 'auth_system.mail.magic_link_verify_notification',
            'magic_link_reset'  => 'auth_system.mail.magic_link_reset_notification',
            'password_reset'    => 'auth_system.mail.otp_reset_notification',
            default             => 'auth_system.mail.otp_verify_notification',
        };

        $customClass = config($configKey);

        if ($customClass !== null && class_exists((string) $customClass)) {
            return new $customClass($code, $type, $context);
        }

        return match ($type) {
            'magic_link_verify', 'magic_link_reset' => new MagicLinkNotification($code, $type, $context),
            default                                  => new OtpCodeNotification($code, $type, $context),
        };
    }
}
