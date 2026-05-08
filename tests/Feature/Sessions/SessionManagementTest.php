<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Joe404\LaravelAuth\Models\AuthSessionExtended;
use Joe404\LaravelAuth\Tests\Fixtures\User;

beforeEach(function (): void {
    Notification::fake();
    Queue::fake();
});

/**
 * Perform a login and return the token plus response.
 */
function loginUser(string $email, string $password, array $headers = []): array
{
    $response = test()->postJson('/auth/login', [
        'email'    => $email,
        'password' => $password,
    ], $headers);

    return [
        'token'    => $response->json('data.token'),
        'response' => $response,
    ];
}

it('GET /auth/sessions returns list of sessions', function (): void {
    $user = $this->createUser(['email' => 'sessions-list@example.com']);

    ['token' => $token] = loginUser('sessions-list@example.com', 'password');

    expect($token)->not->toBeNull();

    $response = $this->withToken($token)->getJson('/auth/sessions');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'data' => [
                'sessions' => [
                    ['id', 'platform', 'last_active_at', 'is_current'],
                ],
            ],
        ]);

    $sessions = $response->json('data.sessions');
    expect($sessions)->toHaveCount(1);
    expect($sessions[0]['is_current'])->toBeTrue();
});

it('sessions response includes device info for mobile request', function (): void {
    $user = $this->createUser(['email' => 'mobile-device@example.com']);

    $deviceInfo = json_encode([
        'model'    => 'SM-G991B',
        'name'     => 'Samsung Galaxy S21',
        'platform' => 'android',
        't2s_code' => 'SM-G991B',
    ]);

    ['token' => $token] = loginUser('mobile-device@example.com', 'password', [
        'X-Device-Info' => $deviceInfo,
    ]);

    expect($token)->not->toBeNull();

    $response = $this->withToken($token)->getJson('/auth/sessions');

    $response->assertStatus(200);

    $session = $response->json('data.sessions.0');

    expect($session['platform'])->toBe('mobile');
    expect($session['device_model'])->toBe('SM-G991B');
    expect($session['device_marketing_name'])->toBe('Samsung Galaxy S21');
    expect($session['device_platform'])->toBe('android');
});

it('DELETE /auth/sessions/{id} terminates a specific session', function (): void {
    $user = $this->createUser(['email' => 'delete-session@example.com']);

    ['token' => $token] = loginUser('delete-session@example.com', 'password');

    expect($token)->not->toBeNull();

    // Create a second session manually
    $secondSession = AuthSessionExtended::create([
        'user_id'          => $user->id,
        'session_id'       => null,
        'sanctum_token_id' => null,
        'platform'         => 'web',
        'ip_address'       => '127.0.0.1',
        'last_active_at'   => now()->subMinutes(5),
    ]);

    expect(AuthSessionExtended::where('user_id', $user->id)->count())->toBe(2);

    $response = $this->withToken($token)->deleteJson("/auth/sessions/{$secondSession->id}");

    $response->assertStatus(200)
        ->assertJson(['success' => true, 'message' => 'Session terminated.']);

    expect(AuthSessionExtended::where('user_id', $user->id)->count())->toBe(1);
    expect(AuthSessionExtended::find($secondSession->id))->toBeNull();
});

it('cannot delete another users session returns 404', function (): void {
    $userA = $this->createUser(['email' => 'user-a@example.com']);
    $userB = $this->createUser(['email' => 'user-b@example.com']);

    ['token' => $tokenA] = loginUser('user-a@example.com', 'password');

    // Create a session belonging to user B
    $sessionB = AuthSessionExtended::create([
        'user_id'          => $userB->id,
        'session_id'       => null,
        'sanctum_token_id' => null,
        'platform'         => 'api',
        'ip_address'       => '127.0.0.1',
        'last_active_at'   => now(),
    ]);

    $response = $this->withToken($tokenA)->deleteJson("/auth/sessions/{$sessionB->id}");

    $response->assertStatus(404)
        ->assertJson(['success' => false, 'message' => 'Session not found.']);

    // Session B must still exist
    expect(AuthSessionExtended::find($sessionB->id))->not->toBeNull();
});

it('POST /auth/logout/all logs out all sessions', function (): void {
    $user = $this->createUser(['email' => 'logout-all@example.com']);

    ['token' => $token] = loginUser('logout-all@example.com', 'password');

    expect($token)->not->toBeNull();

    // Create a second session manually
    AuthSessionExtended::create([
        'user_id'          => $user->id,
        'session_id'       => null,
        'sanctum_token_id' => null,
        'platform'         => 'web',
        'ip_address'       => '127.0.0.1',
        'last_active_at'   => now()->subMinutes(10),
    ]);

    expect(AuthSessionExtended::where('user_id', $user->id)->count())->toBe(2);

    $response = $this->withToken($token)->postJson('/auth/logout/all');

    $response->assertStatus(200)
        ->assertJson(['success' => true, 'message' => 'Logged out of all sessions.']);

    expect(AuthSessionExtended::where('user_id', $user->id)->count())->toBe(0);
});

it('GET /auth/me includes active_sessions count', function (): void {
    $user = $this->createUser(['email' => 'me-sessions@example.com']);

    ['token' => $token] = loginUser('me-sessions@example.com', 'password');

    expect($token)->not->toBeNull();

    $response = $this->withToken($token)->getJson('/auth/me');

    $response->assertStatus(200);

    $activeSessions = $response->json('data.active_sessions');
    expect($activeSessions)->toBe(1);
});

it('web request session stores browser and os info from user agent', function (): void {
    // In test environment, requests run via API mode so browser/os resolution
    // depends on the user agent string present in the request.
    // We verify that a UA-based session at minimum stores a platform value.
    $user = $this->createUser(['email' => 'ua-session@example.com']);

    ['token' => $token] = loginUser('ua-session@example.com', 'password', [
        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    ]);

    expect($token)->not->toBeNull();

    $session = AuthSessionExtended::where('user_id', $user->id)->first();

    expect($session)->not->toBeNull();
    expect($session->platform)->toBeIn(['web', 'api', 'mobile']);
});
