<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Joe404\LaravelAuth\Tests\Fixtures\User;

beforeEach(function (): void {
    // Clear rate limiter cache between tests
    Cache::flush();
    \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'user', 'guard_name' => 'web']);
});

it('returns 429 after exceeding the login rate limit', function (): void {
    // Set a very tight rate limit for this test
    config(['auth_system.rate_limits.login' => '3:1']);

    User::create([
        'name'              => 'Rate Limit User',
        'email'             => 'ratelimit@example.com',
        'password'          => bcrypt('wrong'),
        'email_verified_at' => now(),
        'is_active'         => true,
    ]);

    // Hit the endpoint 3 times (max attempts)
    for ($i = 0; $i < 3; $i++) {
        $this->postJson('/auth/login', [
            'email'    => 'ratelimit@example.com',
            'password' => 'wrong',
        ]);
    }

    // 4th attempt should be rate limited
    $response = $this->postJson('/auth/login', [
        'email'    => 'ratelimit@example.com',
        'password' => 'wrong',
    ]);

    $response->assertStatus(429)
        ->assertJson(['success' => false])
        ->assertHeader('Retry-After');
});

it('rate limits per email independently of IP', function (): void {
    config(['auth_system.rate_limits.login' => '2:1']);

    User::create([
        'name'              => 'Email Rate User',
        'email'             => 'emailrate@example.com',
        'password'          => bcrypt('wrong'),
        'email_verified_at' => now(),
        'is_active'         => true,
    ]);

    /** @var \Joe404\LaravelAuth\Services\RateLimitService $rateLimitService */
    $rateLimitService = app(\Joe404\LaravelAuth\Services\RateLimitService::class);

    // Exhaust the email-specific limit
    $rateLimitService->check('login', 'emailrate@example.com');
    $rateLimitService->check('login', 'emailrate@example.com');

    // Now the email should be rate limited even from a "different" IP
    expect(fn () => $rateLimitService->check('login', 'emailrate@example.com'))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException::class);
});
