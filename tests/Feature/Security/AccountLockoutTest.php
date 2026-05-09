<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Joe404\LaravelAuth\Tests\Fixtures\User;

beforeEach(function (): void {
    \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'user', 'guard_name' => 'web']);

    config([
        'auth_system.security.lockout.enabled'       => true,
        'auth_system.security.lockout.max_attempts'  => 3,
        'auth_system.security.lockout.decay_minutes' => 15,
    ]);

    Cache::flush();
});

it('allows login with correct credentials without triggering lockout', function (): void {
    $user = test()->createUser(['email' => 'lockout@example.com', 'password' => bcrypt('correct')]);

    $response = $this->postJson('/auth/login', [
        'email'    => 'lockout@example.com',
        'password' => 'correct',
    ]);

    $response->assertStatus(200)->assertJson(['success' => true]);
});

it('does not lock out after fewer failures than the threshold', function (): void {
    test()->createUser(['email' => 'lockout2@example.com', 'password' => bcrypt('correct')]);

    // 2 failures (threshold is 3)
    $this->postJson('/auth/login', ['email' => 'lockout2@example.com', 'password' => 'wrong']);
    $this->postJson('/auth/login', ['email' => 'lockout2@example.com', 'password' => 'wrong']);

    $response = $this->postJson('/auth/login', [
        'email'    => 'lockout2@example.com',
        'password' => 'correct',
    ]);

    $response->assertStatus(200)->assertJson(['success' => true]);
});

it('locks account after max failed attempts', function (): void {
    test()->createUser(['email' => 'brute@example.com', 'password' => bcrypt('correct')]);

    // Exhaust all attempts
    for ($i = 0; $i < 3; $i++) {
        $this->postJson('/auth/login', ['email' => 'brute@example.com', 'password' => 'wrong']);
    }

    $response = $this->postJson('/auth/login', [
        'email'    => 'brute@example.com',
        'password' => 'correct',
    ]);

    $response->assertStatus(401)->assertJson(['success' => false]);
    expect($response->json('message'))->toContain('locked');
});

it('clears lockout counter on successful login', function (): void {
    test()->createUser(['email' => 'clear@example.com', 'password' => bcrypt('correct')]);

    // 2 failures (below threshold)
    $this->postJson('/auth/login', ['email' => 'clear@example.com', 'password' => 'wrong']);
    $this->postJson('/auth/login', ['email' => 'clear@example.com', 'password' => 'wrong']);

    // Successful login clears counter
    $this->postJson('/auth/login', ['email' => 'clear@example.com', 'password' => 'correct'])
        ->assertStatus(200);

    // One more failure should not lock (counter was cleared)
    $this->postJson('/auth/login', ['email' => 'clear@example.com', 'password' => 'wrong']);

    $response = $this->postJson('/auth/login', [
        'email'    => 'clear@example.com',
        'password' => 'correct',
    ]);

    $response->assertStatus(200)->assertJson(['success' => true]);
});

it('lockout respects disabled config', function (): void {
    config(['auth_system.security.lockout.enabled' => false]);
    // Decouple from the IP-based rate limiter for this test — we are exercising
    // lockout behavior, not throttling.
    config(['auth_system.rate_limits.login' => '100:1']);
    test()->createUser(['email' => 'nolockout@example.com', 'password' => bcrypt('correct')]);

    for ($i = 0; $i < 10; $i++) {
        $this->postJson('/auth/login', ['email' => 'nolockout@example.com', 'password' => 'wrong']);
    }

    // Even after many failures, account should not be locked when disabled
    $response = $this->postJson('/auth/login', [
        'email'    => 'nolockout@example.com',
        'password' => 'correct',
    ]);

    // Will fail auth (wrong password) but NOT with lockout message
    // After disabling lockout, credential check still works normally
    $response->assertStatus(200)->assertJson(['success' => true]);
});
