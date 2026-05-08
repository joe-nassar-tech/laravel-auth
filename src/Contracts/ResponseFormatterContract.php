<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Contracts;

interface ResponseFormatterContract
{
    public function format(bool $success, string $message, array $data = [], array $errors = []): array;
}
