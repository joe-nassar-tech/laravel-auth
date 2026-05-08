<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Listeners;

use Joe404\LaravelAuth\Events\UserRegistered;

class SendVerificationNotification
{
    public function handle(UserRegistered $event): void
    {
        // Placeholder: actual verification is triggered in the initiate endpoint.
        // M2 will implement additional verification flows here if needed.
    }
}
