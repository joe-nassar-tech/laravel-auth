<?php

declare(strict_types=1);

use Joe404\LaravelAuth\Models\AuthSocialAccount;
use Joe404\LaravelAuth\Tests\Fixtures\User;
use Laravel\Socialite\Facades\Socialite;

function fakeSocialUser(string $id = 'google_uid_123', string $email = 'social@example.com', string $name = 'Social User'): object
{
    return new class ($id, $email, $name) {
        public function __construct(
            private string $id,
            private string $email,
            private string $name,
        ) {}

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
            return $this->name;
        }

        public function getAvatar(): ?string
        {
            return null;
        }
    };
}

function mockSocialiteDriver(object $socialUser, string $redirectUrl = 'https://accounts.google.com/auth'): void
{
    $mockProvider = Mockery::mock(\Laravel\Socialite\Contracts\Provider::class);
    $mockProvider->shouldReceive('stateless')->andReturnSelf();
    $mockProvider->shouldReceive('user')->andReturn($socialUser);

    $redirectResponse = Mockery::mock(\Symfony\Component\HttpFoundation\RedirectResponse::class);
    $redirectResponse->shouldReceive('getTargetUrl')->andReturn($redirectUrl);
    $mockProvider->shouldReceive('redirect')->andReturn($redirectResponse);

    Socialite::shouldReceive('driver')->with('google')->andReturn($mockProvider);
}

beforeEach(function (): void {
    \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'user', 'guard_name' => 'web']);
    config(['auth_system.social.google.enabled' => true]);
});

afterEach(fn () => Mockery::close());

it('redirect endpoint returns google oauth url when enabled', function (): void {
    mockSocialiteDriver(fakeSocialUser());

    $response = $this->getJson('/auth/social/google/redirect');

    $response->assertStatus(200)
        ->assertJsonStructure(['data' => ['redirect_url']])
        ->assertJson(['success' => true]);

    expect($response->json('data.redirect_url'))->toContain('accounts.google.com');
});

it('redirect returns 403 when google auth is disabled', function (): void {
    config(['auth_system.social.google.enabled' => false]);

    $response = $this->getJson('/auth/social/google/redirect');

    $response->assertStatus(403)->assertJson(['success' => false]);
});

it('callback creates new user when social account does not exist', function (): void {
    mockSocialiteDriver(fakeSocialUser('uid_new', 'newuser@gmail.com', 'New User'));

    $response = $this->getJson('/auth/social/google/callback');

    $response->assertStatus(200)
        ->assertJsonStructure(['data' => ['user', 'token']])
        ->assertJson(['success' => true]);

    expect(User::where('email', 'newuser@gmail.com')->exists())->toBeTrue();
    expect(AuthSocialAccount::where('provider_id', 'uid_new')->exists())->toBeTrue();
});

it('callback logs in existing user matched by provider_id', function (): void {
    $user = test()->createUser(['email' => 'existing@gmail.com']);

    AuthSocialAccount::create([
        'user_id'     => $user->id,
        'provider'    => 'google',
        'provider_id' => 'uid_existing_123',
    ]);

    mockSocialiteDriver(fakeSocialUser('uid_existing_123', 'existing@gmail.com', 'Existing User'));

    $response = $this->getJson('/auth/social/google/callback');

    $response->assertStatus(200)->assertJson(['success' => true]);
    expect($response->json('data.token'))->not->toBeNull();
});

it('callback does NOT auto-link when email matches an existing local account (takeover defense)', function (): void {
    $user = test()->createUser(['email' => 'linked@gmail.com']);

    expect(AuthSocialAccount::where('user_id', $user->id)->exists())->toBeFalse();

    mockSocialiteDriver(fakeSocialUser('uid_brand_new', 'linked@gmail.com', 'Linked User'));

    $response = $this->getJson('/auth/social/google/callback');

    // v2: returns 202 + requires_link_confirmation. NOT auto-linked.
    $response->assertStatus(202)
        ->assertJson(['success' => true])
        ->assertJsonFragment(['email' => 'linked@gmail.com']);

    // Crucially: no link row was created. The user must click the confirmation
    // email before the social account is linked.
    expect(AuthSocialAccount::where('user_id', $user->id)->exists())->toBeFalse();
});

it('new google user gets default role assigned', function (): void {
    mockSocialiteDriver(fakeSocialUser('uid_role', 'roletest@gmail.com', 'Role Tester'));

    $this->getJson('/auth/social/google/callback')->assertStatus(200);

    $user = User::where('email', 'roletest@gmail.com')->first();

    expect($user->hasRole('user'))->toBeTrue();
});

it('new google user has email pre-verified', function (): void {
    mockSocialiteDriver(fakeSocialUser('uid_verified', 'verified@gmail.com', 'Verified'));

    $this->getJson('/auth/social/google/callback')->assertStatus(200);

    $user = User::where('email', 'verified@gmail.com')->first();

    expect($user->email_verified_at)->not->toBeNull();
});

it('callback returns 403 for inactive user', function (): void {
    $user = test()->createUser(['email' => 'inactive@gmail.com', 'is_active' => false]);

    AuthSocialAccount::create([
        'user_id'     => $user->id,
        'provider'    => 'google',
        'provider_id' => 'uid_inactive',
    ]);

    mockSocialiteDriver(fakeSocialUser('uid_inactive', 'inactive@gmail.com'));

    $response = $this->getJson('/auth/social/google/callback');

    $response->assertStatus(403)->assertJson(['success' => false]);
});
