<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Joe404\LaravelAuth\Models\AuthTwoFactorMethod;

beforeEach(function (): void {
    \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'user', 'guard_name' => 'web']);
    config(['auth_system.two_factor.enabled' => true]);
});

it('throttles password confirm per user after repeated wrong passwords', function (): void {
    ['token' => $token] = $this->createAuthenticatedUser([
        'email'    => 'pc@example.com',
        'password' => bcrypt('Password123!'),
    ]);

    // password_confirm defaults to 5:1 → five wrong tries return 422, the sixth
    // is throttled. The key is per-user, so this holds regardless of source IP.
    for ($i = 0; $i < 5; $i++) {
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/auth/password/confirm', ['password' => 'wrong-password'])
            ->assertStatus(422);
    }

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/auth/password/confirm', ['password' => 'wrong-password'])
        ->assertStatus(429);
});

it('rate-limits the 2fa challenge switch endpoint (which delivers codes)', function (): void {
    Cache::flush(); // deterministic IP-keyed limiter state
    Notification::fake();
    config(['auth_system.rate_limits.otp_send' => '2:1']);

    $user = $this->createUser(['email' => 'sw@example.com', 'password' => bcrypt('password')]);
    enrollTotp($user); // default method
    AuthTwoFactorMethod::create([
        'user_id'     => $user->getKey(),
        'type'        => 'email',
        'is_default'  => false,
        'verified_at' => now(),
    ]);

    $challenge = $this->postJson('/auth/login', [
        'email' => 'sw@example.com', 'password' => 'password',
    ])->json('data.challenge_token');

    $this->postJson('/auth/2fa/challenge/switch', ['challenge_token' => $challenge, 'method' => 'email'])->assertOk();
    $this->postJson('/auth/2fa/challenge/switch', ['challenge_token' => $challenge, 'method' => 'totp'])->assertOk();
    $this->postJson('/auth/2fa/challenge/switch', ['challenge_token' => $challenge, 'method' => 'email'])->assertStatus(429);
});
