<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Events;

use Illuminate\Foundation\Auth\User;
use Illuminate\Foundation\Events\Dispatchable;

class TrustedDeviceAdded
{
    use Dispatchable;

    public function __construct(
        public User $user,
        public int $deviceId,
        public string $level,
    ) {}
}
