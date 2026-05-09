<?php

declare(strict_types=1);

use Joe404\LaravelAuth\Tests\Fixtures\User;

beforeEach(function (): void {
    \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'user', 'guard_name' => 'web']);
});

it('forgot-password rate limit is NOT auto-cleared on a 2xx response', function (): void {
    config()->set('auth_system.rate_limits.password_reset', '3:1');

    User::create([
        'name' => 'rl', 'email' => 'rl@example.com',
        'password' => bcrypt('x'), 'email_verified_at' => now(), 'is_active' => true,
    ]);

    // Each request returns 200 ("If that email is registered..."). In v1 the
    // middleware cleared the limiter on every 2xx → effectively no rate limit.
    // In v2 the limiter must hold across successful responses.
    for ($i = 0; $i < 3; $i++) {
        $this->postJson('/auth/password/forgot', ['email' => 'rl@example.com'])
            ->assertStatus(200);
    }

    // 4th request must be throttled.
    $this->postJson('/auth/password/forgot', ['email' => 'rl@example.com'])
        ->assertStatus(429);
});
