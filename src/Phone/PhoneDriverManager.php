<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Phone;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;
use Joe404\LaravelAuth\Contracts\PhoneDriverContract;
use Joe404\LaravelAuth\Exceptions\PhoneVerificationException;

class PhoneDriverManager
{
    /** @var array<string, PhoneDriverContract> */
    private array $resolved = [];

    /** @var array<string, callable(Container, array<string,mixed>): PhoneDriverContract> */
    private array $customFactories = [];

    public function __construct(private readonly Container $container) {}

    /**
     * Register a host-side custom driver factory.
     *
     * @param  callable(Container, array<string,mixed>): PhoneDriverContract  $factory
     */
    public function extend(string $providerKey, callable $factory): void
    {
        $this->customFactories[$providerKey] = $factory;
        unset($this->resolved[$providerKey]);
    }

    public function provider(string $providerKey): PhoneDriverContract
    {
        if (isset($this->resolved[$providerKey])) {
            return $this->resolved[$providerKey];
        }

        $config = (array) config("auth_system.phone.providers.{$providerKey}", []);

        if ($config === []) {
            throw new PhoneVerificationException(
                "Phone provider '{$providerKey}' is not configured.",
                'phone_provider_missing',
            );
        }

        if (isset($this->customFactories[$providerKey])) {
            return $this->resolved[$providerKey] = ($this->customFactories[$providerKey])($this->container, $config);
        }

        $driverClass = (string) ($config['driver'] ?? '');

        if ($driverClass === '' || ! class_exists($driverClass)) {
            throw new PhoneVerificationException(
                "Phone driver class for provider '{$providerKey}' is missing or invalid.",
                'phone_driver_missing',
            );
        }

        return $this->resolved[$providerKey] = $this->container->make($driverClass, ['config' => $config]);
    }

    /**
     * Send a code on the configured channel, falling back to the channel's
     * fallback provider if the primary throws.
     *
     * @param  array<string,mixed>  $context
     */
    public function send(string $channel, string $phone, string $code, array $context = []): string
    {
        $channels = (array) config('auth_system.phone.channels', []);
        $config   = (array) ($channels[$channel] ?? []);

        $primary  = (string) ($config['provider'] ?? '');
        $fallback = $config['fallback'] ?? null;

        if ($primary === '') {
            throw new PhoneVerificationException(
                "No provider configured for channel '{$channel}'.",
                'phone_channel_unconfigured',
            );
        }

        try {
            $this->dispatch($primary, $channel, $phone, $code, $context);

            return $primary;
        } catch (PhoneVerificationException $e) {
            if (! is_string($fallback) || $fallback === '') {
                throw $e;
            }

            Log::warning('[laravel-auth] Primary phone provider failed; using fallback.', [
                'channel'  => $channel,
                'primary'  => $primary,
                'fallback' => $fallback,
                'error'    => $e->getMessage(),
            ]);

            $this->dispatch($fallback, $channel, $phone, $code, $context);

            return $fallback;
        }
    }

    /** @param array<string,mixed> $context */
    private function dispatch(string $providerKey, string $channel, string $phone, string $code, array $context): void
    {
        $driver = $this->provider($providerKey);

        if (! in_array($channel, $driver->supports(), true)) {
            throw new PhoneVerificationException(
                "Provider '{$providerKey}' does not support channel '{$channel}'.",
                'phone_channel_unsupported',
            );
        }

        $driver->send($phone, $code, $channel, $context);
    }
}
