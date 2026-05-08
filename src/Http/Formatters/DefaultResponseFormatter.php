<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Http\Formatters;

use Joe404\LaravelAuth\Contracts\ResponseFormatterContract;

class DefaultResponseFormatter implements ResponseFormatterContract
{
    public function format(bool $success, string $message, array $data = [], array $errors = []): array
    {
        return [
            'success' => $success,
            'message' => $message,
            'data'    => $data,
            'errors'  => $errors,
        ];
    }
}
