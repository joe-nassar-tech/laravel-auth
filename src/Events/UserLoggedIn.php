<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Events;

use Illuminate\Foundation\Auth\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Http\Request;

class UserLoggedIn
{
    use Dispatchable;

    public function __construct(
        public readonly User $user,
        public readonly Request $request,
    ) {}
}
