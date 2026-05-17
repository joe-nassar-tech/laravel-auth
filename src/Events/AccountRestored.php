<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Events;

use Illuminate\Foundation\Auth\User;
use Illuminate\Foundation\Events\Dispatchable;

class AccountRestored
{
    use Dispatchable;

    public function __construct(
        public readonly User $user,
        public readonly string $trigger = 'login',
    ) {}
}
