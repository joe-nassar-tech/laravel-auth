<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Contracts;

interface PhoneDriverContract
{
    /**
     * Send a code via the requested channel.
     *
     * @param  string  $phone     E.164 phone number (e.g. +14155552671)
     * @param  string  $code      Plain numeric code to deliver
     * @param  string  $channel   sms|voice|whatsapp
     * @param  array<string,mixed>  $context  Optional metadata: locale, sender_id, template_id, etc.
     */
    public function send(string $phone, string $code, string $channel, array $context = []): void;

    /**
     * Declare which channels this driver supports. Channels not in the list
     * trigger a config exception at boot rather than failing silently.
     *
     * @return array<int,string>
     */
    public function supports(): array;

    /**
     * Provider key as configured under auth_system.phone.providers.<key>.
     */
    public function name(): string;
}
