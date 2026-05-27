<?php

declare(strict_types=1);

use Laravel\Socialite\Facades\Socialite;

beforeEach(function (): void {
    \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'user', 'guard_name' => 'web']);
    config([
        'auth_system.social.google.enabled' => true,
        'auth_system.mode'                   => 'api', // stateless client path
    ]);
});

afterEach(fn () => Mockery::close());

function mockGoogleStateDriver(string $redirectUrl = 'https://accounts.google.com/o/oauth2/auth'): void
{
    // Stable id/email for the whole request (computed once, not per getId() call).
    $user = new class {
        public string $id;
        public string $email;

        public function __construct()
        {
            $this->id    = 'gid_' . uniqid();
            $this->email = 'state_' . uniqid() . '@example.com';
        }

        public function getId(): string
        {
            return $this->id;
        }

        public function getEmail(): string
        {
            return $this->email;
        }

        public function getName(): string
        {
            return 'State User';
        }

        public function getAvatar(): ?string
        {
            return null;
        }
    };

    $provider = Mockery::mock(\Laravel\Socialite\Contracts\Provider::class);
    $provider->shouldReceive('stateless')->andReturnSelf();
    $provider->shouldReceive('user')->andReturn($user);

    $redirect = Mockery::mock(\Symfony\Component\HttpFoundation\RedirectResponse::class);
    $redirect->shouldReceive('getTargetUrl')->andReturn($redirectUrl);
    $provider->shouldReceive('redirect')->andReturn($redirect);

    Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
}

it('rejects the stateless callback when enforce_state is on and no valid state is presented', function (): void {
    config(['auth_system.social.enforce_state' => true]);
    mockGoogleStateDriver();

    $this->getJson('/auth/social/google/callback')
        ->assertStatus(401)
        ->assertJsonPath('success', false);
});

it('accepts the stateless callback when the issued one-time state is presented', function (): void {
    config(['auth_system.social.enforce_state' => true]);
    mockGoogleStateDriver();

    $redirectUrl = $this->getJson('/auth/social/google/redirect')->json('data.redirect_url');
    parse_str((string) parse_url($redirectUrl, PHP_URL_QUERY), $params);
    $state = $params['state'] ?? null;

    expect($state)->not->toBeNull();

    $this->getJson('/auth/social/google/callback?state=' . $state)->assertOk();
});

it('rejects a replayed state (one-time use)', function (): void {
    config(['auth_system.social.enforce_state' => true]);
    mockGoogleStateDriver();

    $redirectUrl = $this->getJson('/auth/social/google/redirect')->json('data.redirect_url');
    parse_str((string) parse_url($redirectUrl, PHP_URL_QUERY), $params);
    $state = $params['state'];

    $this->getJson('/auth/social/google/callback?state=' . $state)->assertOk();
    // Second use of the same state is rejected.
    $this->getJson('/auth/social/google/callback?state=' . $state)->assertStatus(401);
});

it('does not enforce state when the flag is off (back-compat)', function (): void {
    config(['auth_system.social.enforce_state' => false]);
    mockGoogleStateDriver();

    $this->getJson('/auth/social/google/callback')->assertOk();
});
