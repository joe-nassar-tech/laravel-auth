<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Events;

use Illuminate\Foundation\Auth\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Carbon;
use Joe404\LaravelAuth\Models\DeletedAccount;

class AccountDeleted
{
    use Dispatchable;

    public function __construct(
        public readonly User $user,
        public readonly DeletedAccount $deletedAccount,
        public readonly Carbon $scheduledPurgeAt,
        public readonly ?string $reason = null,
    ) {}
}
