<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Joe404\LaravelAuth\Models\DeletedAccount;

class AccountPurged
{
    use Dispatchable;

    /**
     * @param  array<int, string>  $nulledColumns
     */
    public function __construct(
        public readonly DeletedAccount $deletedAccount,
        public readonly array $nulledColumns,
        public readonly bool $hardDeleted,
    ) {}
}
