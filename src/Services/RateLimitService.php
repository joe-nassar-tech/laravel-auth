<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Services;

use Illuminate\Cache\RateLimiter;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

class RateLimitService
{
    public function __construct(
        private readonly RateLimiter $limiter,
    ) {}

    public function check(string $configKey, string $subject): void
    {
        $key    = $this->key($configKey, $subject);
        $config = $this->parseConfig($configKey);

        if ($this->limiter->tooManyAttempts($key, $config['max'])) {
            $seconds = $this->limiter->availableIn($key);
            throw new TooManyRequestsHttpException(
                $seconds,
                "Too many attempts. Please try again in {$seconds} seconds.",
            );
        }

        $this->limiter->hit($key, $config['decay'] * 60);
    }

    public function key(string $configKey, string $subject): string
    {
        return "auth:{$configKey}:" . sha1($subject);
    }

    public function clear(string $configKey, string $subject): void
    {
        $this->limiter->clear($this->key($configKey, $subject));
    }

    private function parseConfig(string $configKey): array
    {
        $raw   = (string) config("auth_system.rate_limits.{$configKey}", '5:1');
        $parts = explode(':', $raw, 2);

        return [
            'max'   => (int) ($parts[0] ?? 5),
            'decay' => (int) ($parts[1] ?? 1),
        ];
    }
}
