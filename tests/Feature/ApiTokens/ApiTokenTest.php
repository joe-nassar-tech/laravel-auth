<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Joe404\LaravelAuth\Jobs\CleanExpiredApiTokens;
use Joe404\LaravelAuth\Models\AuthApiToken;

function makeAdminToken(): string
{
    $admin = test()->createUser(['email' => 'admin_' . uniqid() . '@example.com']);
    $admin->assignRole('admin');

    return $admin->createToken('test')->plainTextToken;
}

beforeEach(function (): void {
    // Enable the api_tokens feature for this test file (gated by FeatureFlag middleware).
    config()->set('auth_system.api_tokens.enabled', true);

    // Seed roles
    foreach (['super-admin', 'admin', 'user'] as $role) {
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
    }

    // Register protected test route
    $this->app->make('router')
        ->middleware(['auth.api-token:read:orders'])
        ->get('/_test/api-protected', fn () => response()->json(['ok' => true]));
});

it('admin can issue a client api token', function (): void {
    $adminToken = makeAdminToken();

    $response = $this->withToken($adminToken)->postJson('/auth/admin/api-tokens', [
        'name'      => 'Test Client Token',
        'abilities' => ['read:orders', 'write:orders'],
    ]);

    $response->assertStatus(201);

    expect($response->json('data.raw_token'))->toStartWith('auth_at_');
    expect($response->json('data.token.id'))->not->toBeNull();
    expect($response->json('data.token.name'))->toBe('Test Client Token');
    expect($response->json('data.token.abilities'))->toContain('read:orders');
});

it('raw token is not stored in database', function (): void {
    $adminToken = makeAdminToken();

    $response = $this->withToken($adminToken)->postJson('/auth/admin/api-tokens', [
        'name' => 'DB Check Token',
    ]);

    $response->assertStatus(201);

    $rawToken = $response->json('data.raw_token');

    $this->assertDatabaseMissing('auth_api_tokens', ['token_hash' => $rawToken]);
});

it('token hash in db matches sha256 of decoded raw token', function (): void {
    $adminToken = makeAdminToken();

    $response = $this->withToken($adminToken)->postJson('/auth/admin/api-tokens', [
        'name' => 'Hash Check Token',
    ]);

    $response->assertStatus(201);

    $rawBearer = $response->json('data.raw_token');
    $encoded   = substr($rawBearer, strlen('auth_at_'));
    $rawToken  = base64_decode($encoded, true);

    expect($rawToken)->not->toBeFalse();

    $expectedHash = hash('sha256', $rawToken);

    $this->assertDatabaseHas('auth_api_tokens', ['token_hash' => $expectedHash]);
});

it('valid token with correct ability passes middleware', function (): void {
    $adminToken = makeAdminToken();

    $response = $this->withToken($adminToken)->postJson('/auth/admin/api-tokens', [
        'name'      => 'Orders Token',
        'abilities' => ['read:orders'],
    ]);

    $response->assertStatus(201);

    $rawToken = $response->json('data.raw_token');

    $this->getJson('/_test/api-protected', ['Authorization' => "Bearer {$rawToken}"])
        ->assertStatus(200);
});

it('valid token with missing ability returns 403', function (): void {
    $adminToken = makeAdminToken();

    $response = $this->withToken($adminToken)->postJson('/auth/admin/api-tokens', [
        'name'      => 'Wrong Ability Token',
        'abilities' => ['read:something-else'],
    ]);

    $response->assertStatus(201);

    $rawToken = $response->json('data.raw_token');

    $this->getJson('/_test/api-protected', ['Authorization' => "Bearer {$rawToken}"])
        ->assertStatus(403);
});

it('expired token returns 401', function (): void {
    $raw    = Str::random(64);
    $bearer = 'auth_at_' . base64_encode($raw);

    AuthApiToken::create([
        'name'       => 'expired',
        'token_hash' => hash('sha256', $raw),
        'abilities'  => ['read:orders'],
        'is_active'  => true,
        'expires_at' => now()->subMinute(),
    ]);

    $this->getJson('/_test/api-protected', ['Authorization' => "Bearer {$bearer}"])
        ->assertStatus(401);
});

it('revoked token returns 401', function (): void {
    $adminToken = makeAdminToken();

    $response = $this->withToken($adminToken)->postJson('/auth/admin/api-tokens', [
        'name'      => 'Revoke Me Token',
        'abilities' => ['read:orders'],
    ]);

    $response->assertStatus(201);

    $rawToken = $response->json('data.raw_token');
    $tokenId  = $response->json('data.token.id');

    // Revoke via admin endpoint
    $this->withToken($adminToken)->deleteJson("/auth/admin/api-tokens/{$tokenId}")
        ->assertStatus(200);

    $this->getJson('/_test/api-protected', ['Authorization' => "Bearer {$rawToken}"])
        ->assertStatus(401);
});

it('malformed authorization header returns 401', function (): void {
    $this->getJson('/_test/api-protected', ['Authorization' => 'Bearer invalid_token'])
        ->assertStatus(401);
});

it('last_used_at is updated on valid use', function (): void {
    $adminToken = makeAdminToken();

    $response = $this->withToken($adminToken)->postJson('/auth/admin/api-tokens', [
        'name'      => 'Track Usage Token',
        'abilities' => ['read:orders'],
    ]);

    $response->assertStatus(201);

    $rawToken = $response->json('data.raw_token');
    $tokenId  = $response->json('data.token.id');

    $this->getJson('/_test/api-protected', ['Authorization' => "Bearer {$rawToken}"])
        ->assertStatus(200);

    $token = AuthApiToken::find($tokenId);
    expect($token->last_used_at)->not->toBeNull();
});

it('cleanup job deletes expired tokens', function (): void {
    Queue::fake();

    $raw = Str::random(64);

    AuthApiToken::create([
        'name'       => 'expired-cleanup',
        'token_hash' => hash('sha256', $raw),
        'abilities'  => ['read'],
        'is_active'  => true,
        'expires_at' => now()->subHour(),
    ]);

    $this->assertDatabaseHas('auth_api_tokens', ['name' => 'expired-cleanup']);

    // Run job synchronously (bypass queue)
    app(\Joe404\LaravelAuth\Services\ApiTokenService::class)->deleteExpired();

    $this->assertDatabaseMissing('auth_api_tokens', ['name' => 'expired-cleanup']);
});
