<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Contracts;

interface OtpChannelContract
{
    public function send(string $recipient, string $code, string $type, array $context = []): void;
}
