<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Joe404\LaravelAuth\Http\Middleware\Require2FA;
use Joe404\LaravelAuth\Services\TokenService;
use Joe404\LaravelAuth\Tests\Fixtures\User;

beforeEach(function (): void {
    \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'user', 'guard_name' => 'web']);
});

// ── #4 login timing-based enumeration ────────────────────────────────────────

it('runs a password hash check for a non-existent email so timing matches a real login', function (): void {
    // Mock returns a real hash so the flow continues; the assertion is that the
    // missing-user branch performs a bcrypt check at all (pre-fix it did not,
    // returning instantly and leaking existence via response latency).
    Hash::shouldReceive('make')->andReturnUsing(fn ($v) => password_hash((string) $v, PASSWORD_BCRYPT));
    Hash::shouldReceive('check')->atLeast()->once()->andReturn(false);

    $this->postJson('/auth/login', [
        'email'    => 'ghost@example.com',
        'password' => 'a-sufficiently-long-password',
    ])->assertStatus(401)->assertJsonPath('success', false);
});

// ── #5 refresh response must not leak the password hash ──────────────────────

it('does not leak password / remember_token on token refresh even when the user model has no $hidden', function (): void {
    // A user model that hides nothing — exactly the host misconfiguration the
    // safeUserArray() safety net exists to defend against.
    $visible = new class extends User {
        protected $hidden = [];
    };
    $model = $visible::class;

    config(['auth.providers.users.model' => $model]);

    $user = $model::create([
        'name'              => 'Vis Ible',
        'email'             => 'visible@example.com',
        'password'          => bcrypt('password'),
        'email_verified_at' => now(),
        'is_active'         => true,
    ]);

    $refreshToken = app(TokenService::class)->issue($user, 'mobile')['plain_refresh_token'];

    $payload = $this->withHeader('X-Client-Type', 'mobile')
        ->postJson('/auth/token/refresh', ['refresh_token' => $refreshToken])
        ->assertOk()
        ->json('data.user');

    expect($payload)->toBeArray();
    expect(array_key_exists('password', $payload))->toBeFalse();
    expect(array_key_exists('remember_token', $payload))->toBeFalse();
});

// ── #6 step-up cache keys are consistent for string (UUID) user ids ──────────

it('recognizes a password-confirm sudo stamp for a string (UUID) user id', function (): void {
    // A user whose primary key is a UUID string. Pre-fix, Require2FA built the
    // sudo cache key with (int) $user->getKey() — "uuid-xyz" collapses to 0 —
    // while PasswordConfirmController wrote it with the raw key, so the stamp
    // was never found and step-up silently failed. Both now use the raw key.
    $user = new class extends User {
        public function getKey(): string
        {
            return 'uuid-xyz';
        }
    };

    config(['auth_system.two_factor.middleware_behavior' => 'password_confirm']);

    $session = new Store('test_session', new ArraySessionHandler(120));
    $request = Request::create('/protected', 'POST');
    $request->setLaravelSession($session);
    $request->setUserResolver(fn () => $user);

    // Stamp the sudo window exactly as PasswordConfirmController would: raw
    // user key + the real session id. Pre-fix the middleware looked under
    // "auth:sudo:0:{sid}" ((int) "uuid-xyz" === 0) and missed this entirely.
    Cache::put('auth:sudo:uuid-xyz:' . $session->getId(), true, now()->addMinutes(15));

    $response = app(Require2FA::class)->handle(
        $request,
        fn ($req) => new \Illuminate\Http\Response('ok', 200),
    );

    expect($response->getStatusCode())->toBe(200);
});
