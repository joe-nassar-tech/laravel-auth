<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Events;

use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;

class UserLoggedIn
{
    public function __construct(
        public readonly User $user,
        public readonly Request $request,
    ) {}
}
