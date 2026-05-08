<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Http\Concerns;

use Illuminate\Http\JsonResponse;
use Joe404\LaravelAuth\Contracts\ResponseFormatterContract;

trait RespondsWithJson
{
    public function success(string $message, array $data = [], int $status = 200): JsonResponse
    {
        return response()->json(
            $this->formatter()->format(true, $message, $data, []),
            $status
        );
    }

    public function failure(string $message, array $errors = [], int $status = 422): JsonResponse
    {
        return response()->json(
            $this->formatter()->format(false, $message, [], $errors),
            $status
        );
    }

    private function formatter(): ResponseFormatterContract
    {
        return app(ResponseFormatterContract::class);
    }
}
