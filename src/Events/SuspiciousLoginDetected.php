<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Events;

use Illuminate\Foundation\Events\Dispatchable;

class SuspiciousLoginDetected
{
    use Dispatchable;

    public function __construct(
        public readonly mixed $user,
        public readonly string $ipAddress,
        public readonly ?string $browser,
        public readonly ?string $os,
        public readonly ?string $city,
        public readonly ?string $country,
    ) {}
}
