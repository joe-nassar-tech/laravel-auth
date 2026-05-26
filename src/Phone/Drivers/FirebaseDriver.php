<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Phone\Drivers;

use Joe404\LaravelAuth\Contracts\PhoneDriverContract;
use Joe404\LaravelAuth\Exceptions\PhoneVerificationException;

/**
 * Firebase Phone Auth is a client-side flow — the SDK delivers the SMS code
 * directly to the device and returns an ID token. Backends typically VERIFY
 * the ID token rather than send codes. This driver therefore acts as a marker
 * for hosts that handle delivery client-side; the package raises a clear
 * error if you try to use it server-side for send().
 *
 * Hosts using Firebase should verify the ID token in their own controller and
 * call the package's mark-verified service directly — see docs/phone.md.
 */
class FirebaseDriver implements PhoneDriverContract
{
    /** @param array<string,mixed> $config */
    public function __construct(private readonly array $config) {}

    public function send(string $phone, string $code, string $channel, array $context = []): void
    {
        throw new PhoneVerificationException(
            'Firebase Phone Auth is a client-side flow; codes are not sent from the server. '
            . 'Verify the Firebase ID token in your controller and call the mark-verified service.',
            'phone_driver_client_side_only',
        );
    }

    public function supports(): array
    {
        return ['sms'];
    }

    public function name(): string
    {
        return 'firebase';
    }
}
