<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Auth\User;
use Illuminate\Foundation\Events\Dispatchable;

class EmailVerified implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public readonly User $user,
        public readonly string $tempToken,
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
            'verified' => true,
            'redirect' => '/dashboard',
        ];
    }

    public function broadcastAs(): string
    {
        return 'EmailVerified';
    }
}
