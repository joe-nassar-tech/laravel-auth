<?php

declare(strict_types=1);

use Joe404\LaravelAuth\Models\AuthTwoFactorChallenge;

beforeEach(function (): void {
    \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'user', 'guard_name' => 'web']);
});

it('the conditional challenge consume is atomic — only one update wins the row', function (): void {
    $user = $this->createUser(['email' => 'ch-race@example.com']);

    $challenge = AuthTwoFactorChallenge::create([
        'challenge_token' => str_repeat('a', 64),
        'user_id'         => $user->getKey(),
        'method'          => 'totp',
        'attempts'        => 0,
        'expires_at'      => now()->addMinutes(5),
        'created_at'      => now(),
    ]);

    // Simulate two concurrent verifies with valid codes for two different
    // enrolled factors — both reach the final consume step.
    $first = AuthTwoFactorChallenge::where('id', $challenge->getKey())
        ->whereNull('consumed_at')
        ->update(['consumed_at' => now(), 'method' => 'totp']);

    $second = AuthTwoFactorChallenge::where('id', $challenge->getKey())
        ->whereNull('consumed_at')
        ->update(['consumed_at' => now(), 'method' => 'email']);

    expect($first)->toBe(1);
    expect($second)->toBe(0); // even with a different (valid) factor, the row is single-use
});
