<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Events;

use Illuminate\Foundation\Auth\User;

class UserRegistered
{
    public function __construct(
        public readonly User $user,
    ) {}
}
