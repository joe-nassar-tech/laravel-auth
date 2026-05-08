<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Listeners;

use Joe404\LaravelAuth\Events\SuspiciousLoginDetected;
use Joe404\LaravelAuth\Notifications\NewDeviceLoginNotification;

class NotifySuspiciousLogin
{
    public function handle(SuspiciousLoginDetected $event): void
    {
        if (! (bool) config('auth_system.security.notify_new_device_login', true)) {
            return;
        }

        if (! method_exists($event->user, 'notify')) {
            return;
        }

        $event->user->notify(new NewDeviceLoginNotification(
            ipAddress: $event->ipAddress,
            browser: $event->browser,
            os: $event->os,
            city: $event->city,
            country: $event->country,
        ));
    }
}
