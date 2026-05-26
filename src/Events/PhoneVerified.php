<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Events;

use Illuminate\Foundation\Auth\User;
use Illuminate\Foundation\Events\Dispatchable;

class PhoneVerified
{
    use Dispatchable;

    public function __construct(
        public User $user,
        public string $phone,
    ) {}
}
