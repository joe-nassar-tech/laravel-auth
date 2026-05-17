<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Events;

use Illuminate\Foundation\Auth\User;
use Illuminate\Foundation\Events\Dispatchable;

class AccountStatusChanged
{
    use Dispatchable;

    public function __construct(
        public readonly User $user,
        public readonly string $previousStatus,
        public readonly string $newStatus,
        public readonly ?string $reason = null,
    ) {}
}
