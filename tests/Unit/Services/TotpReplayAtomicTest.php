<?php

declare(strict_types=1);

use Joe404\LaravelAuth\Models\AuthTwoFactorMethod;

beforeEach(function (): void {
    \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'user', 'guard_name' => 'web']);
});

it('the timestep update strictly advances and rejects same-step / regression races', function (): void {
    $user = $this->createUser(['email' => 'totp-race@example.com']);

    $method = AuthTwoFactorMethod::create([
        'user_id'          => $user->getKey(),
        'type'             => 'totp',
        'secret_encrypted' => 'placeholder',
        'is_default'       => true,
        'verified_at'      => now(),
    ]);

    $step = 1000;

    $apply = fn (int $candidate) => AuthTwoFactorMethod::where('id', $method->getKey())
        ->where(function ($q) use ($candidate) {
            $q->whereNull('last_totp_timestep')
              ->orWhere('last_totp_timestep', '<', $candidate);
        })
        ->update(['last_totp_timestep' => $candidate]);

    // First request advances NULL → 1000.
    expect($apply($step))->toBe(1);

    // Concurrent request computing the SAME matched step (race) → 0 affected
    // rows, so verifyTotp returns false (the loser's replay is rejected).
    expect($apply($step))->toBe(0);

    // Attempt to regress to an older step → 0 (monotonic guard).
    expect($apply($step - 1))->toBe(0);

    // A genuinely newer step → 1 (next code's step advances cleanly).
    expect($apply($step + 1))->toBe(1);
});
