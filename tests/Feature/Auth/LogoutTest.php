<?php

declare(strict_types=1);

use Joe404\LaravelAuth\Models\AuthSessionExtended;
use Joe404\LaravelAuth\Tests\Fixtures\User;

it('can logout with a valid sanctum token', function (): void {
    $user  = User::create([
        'name'              => 'Logout User',
        'email'             => 'logout@example.com',
        'password'          => bcrypt('password'),
        'email_verified_at' => now(),
        'is_active'         => true,
    ]);
    $token = $user->createToken('auth-token')->plainTextToken;

    $response = $this->withHeaders([
        'Authorization' => "Bearer {$token}",
    ])->postJson('/auth/logout');

    $response->assertStatus(200)
        ->assertJson(['success' => true, 'message' => 'Logged out successfully.']);

    // Token should be revoked
    expect($user->tokens()->count())->toBe(0);
});

it('returns 401 when trying to logout without authentication', function (): void {
    $response = $this->postJson('/auth/logout');

    $response->assertStatus(401);
});

it('logout/all removes all sessions', function (): void {
    $user  = User::create([
        'name'              => 'Logout All User',
        'email'             => 'logoutall@example.com',
        'password'          => bcrypt('password'),
        'email_verified_at' => now(),
        'is_active'         => true,
    ]);

    // Create two tokens and their corresponding session records
    $token1 = $user->createToken('token-1')->accessToken;
    $token2 = $user->createToken('token-2')->accessToken;

    AuthSessionExtended::create([
        'user_id'          => $user->id,
        'sanctum_token_id' => $token1->id,
        'platform'         => 'api',
        'ip_address'       => '127.0.0.1',
        'last_active_at'   => now(),
    ]);

    AuthSessionExtended::create([
        'user_id'          => $user->id,
        'sanctum_token_id' => $token2->id,
        'platform'         => 'api',
        'ip_address'       => '127.0.0.1',
        'last_active_at'   => now()->subMinutes(5),
    ]);

    $plainToken = $user->createToken('current-token')->plainTextToken;

    $response = $this->withHeaders([
        'Authorization' => "Bearer {$plainToken}",
    ])->postJson('/auth/logout/all');

    $response->assertStatus(200)
        ->assertJson(['success' => true, 'message' => 'Logged out of all sessions.']);

    // logoutAll preserves the calling session/token so the response itself
    // doesn't 401 the caller. Other sessions/tokens are gone.
    expect(AuthSessionExtended::where('user_id', $user->id)->count())->toBeLessThanOrEqual(1);
    expect($user->tokens()->count())->toBe(1);
});
