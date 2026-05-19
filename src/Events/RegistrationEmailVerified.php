<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired after a successful OTP / magic-link verification, BEFORE the user
 * row exists. Carries the freshly-issued `completion_token` so an "original"
 * tab waiting on the verify screen can pick the registration back up when
 * the link was clicked elsewhere (different tab, browser, or device).
 *
 * Distinct from `EmailVerified`, which fires after the user has set their
 * password (the user row exists at that point). Use this earlier event to
 * drive cross-tab handoff in your SPA; use `EmailVerified` for "the user is
 * now a real account" side effects (wallet seeding, analytics, welcome mail).
 */
class RegistrationEmailVerified implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public readonly string $tempToken,
        public readonly string $completionToken,
        public readonly string $email,
    ) {}

    /**
     * @return array<Channel>
     */
    public function broadcastOn(): array
    {
        $reverbEnabled = (bool) config('auth_system.reverb.enabled', false);

        if (! $reverbEnabled || ! class_exists(\Laravel\Reverb\ReverbServiceProvider::class)) {
            return [];
        }

        return [new PrivateChannel("auth.verification.{$this->tempToken}")];
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'verified'         => true,
            'completion_token' => $this->completionToken,
            'email'            => $this->email,
        ];
    }

    public function broadcastAs(): string
    {
        return 'RegistrationEmailVerified';
    }
}
