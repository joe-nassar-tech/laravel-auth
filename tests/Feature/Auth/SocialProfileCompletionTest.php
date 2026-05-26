<?php

declare(strict_types=1);

use Joe404\LaravelAuth\Models\AuthSocialAccount;
use Joe404\LaravelAuth\Tests\Fixtures\User;
use Laravel\Socialite\Facades\Socialite;

// Local helpers (uniquely named to avoid clashing with SocialAuthTest.php).

function pcSocialUser(string $id = 'pc_uid', string $email = 'pcnew@gmail.com', string $name = 'PC User'): object
{
    return new class ($id, $email, $name) {
        public function __construct(private string $id, private string $email, private string $name) {}
        public function getId(): string { return $this->id; }
        public function getEmail(): string { return $this->email; }
        public function getName(): string { return $this->name; }
        public function getAvatar(): ?string { return null; }
    };
}

function pcMockSocialite(object $socialUser): void
{
    $provider = Mockery::mock(\Laravel\Socialite\Contracts\Provider::class);
    $provider->shouldReceive('stateless')->andReturnSelf();
    $provider->shouldReceive('user')->andReturn($socialUser);
    Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
}

beforeEach(function (): void {
    \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'user', 'guard_name' => 'web']);
    config([
        'auth_system.social.google.enabled'             => true,
        'auth_system.social.profile_completion.enabled' => true,
        // Require a phone so a brand-new Google user must complete their profile.
        'auth_system.phone.enabled'  => true,
        'auth_system.phone.required' => true,
    ]);
});

afterEach(fn () => Mockery::close());

it('returns requires_profile_completion (no user, no token) for a brand-new google user', function (): void {
    pcMockSocialite(pcSocialUser('uid_pc1', 'pc1@gmail.com'));

    $response = $this->getJson('/auth/social/google/callback');

    $response->assertStatus(202)
        ->assertJsonPath('data.requires_profile_completion', true)
        ->assertJsonPath('data.prefill.email', 'pc1@gmail.com')
        ->assertJsonStructure(['data' => ['completion_token', 'prefill' => ['email', 'name']]]);

    // Nothing persisted yet.
    expect(User::where('email', 'pc1@gmail.com')->exists())->toBeFalse();
    expect($response->json('data.token'))->toBeNull();
});

it('creates the user and logs in when required fields are submitted to /social/complete', function (): void {
    pcMockSocialite(pcSocialUser('uid_pc2', 'pc2@gmail.com', 'PC Two'));

    $token = $this->getJson('/auth/social/google/callback')->json('data.completion_token');

    $response = $this->postJson('/auth/social/complete', [
        'completion_token' => $token,
        'phone'            => '+14155550123',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.token', fn ($t) => is_string($t) && strlen($t) > 10);

    $user = User::where('email', 'pc2@gmail.com')->first();
    expect($user)->not->toBeNull();
    expect($user->phone)->toBe('+14155550123');
    expect($user->email_verified_at)->not->toBeNull();
    expect(AuthSocialAccount::where('user_id', $user->getKey())->where('provider', 'google')->exists())->toBeTrue();
});

it('rejects /social/complete when a required field is missing', function (): void {
    pcMockSocialite(pcSocialUser('uid_pc3', 'pc3@gmail.com'));

    $token = $this->getJson('/auth/social/google/callback')->json('data.completion_token');

    $this->postJson('/auth/social/complete', [
        'completion_token' => $token,
        // phone omitted
    ])->assertStatus(422)
      ->assertJsonPath('success', false);

    expect(User::where('email', 'pc3@gmail.com')->exists())->toBeFalse();
});

it('rejects /social/complete with an invalid completion token', function (): void {
    $this->postJson('/auth/social/complete', [
        'completion_token' => str_repeat('0', 36),
        'phone'            => '+14155550999',
    ])->assertStatus(422)
      ->assertJsonPath('success', false);
});

it('blocks /social/complete when a required extra field is missing', function (): void {
    config([
        'auth_system.phone.required' => false,
        'auth_system.registration.extra_fields_rules' => ['username' => 'required|string|min:3'],
    ]);

    pcMockSocialite(pcSocialUser('uid_pc4', 'pc4@gmail.com'));
    $token = $this->getJson('/auth/social/google/callback')->json('data.completion_token');

    $this->postJson('/auth/social/complete', ['completion_token' => $token])
        ->assertStatus(422);

    expect(User::where('email', 'pc4@gmail.com')->exists())->toBeFalse();
});

it('stores a required extra field submitted to /social/complete', function (): void {
    config([
        'auth_system.phone.required' => false,
        'auth_system.registration.extra_fields_rules' => ['username' => 'required|string|min:3'],
    ]);

    pcMockSocialite(pcSocialUser('uid_pc4b', 'pc4b@gmail.com'));
    $token = $this->getJson('/auth/social/google/callback')->json('data.completion_token');

    $this->postJson('/auth/social/complete', [
        'completion_token' => $token,
        'username'         => 'pcuser',
    ])->assertOk();

    expect(User::where('email', 'pc4b@gmail.com')->first()->username)->toBe('pcuser');
});

it('logs in immediately (no completion step) when profile_completion is disabled', function (): void {
    config([
        'auth_system.social.profile_completion.enabled' => false,
        'auth_system.phone.required' => false,
    ]);

    pcMockSocialite(pcSocialUser('uid_pc5', 'pc5@gmail.com'));

    $this->getJson('/auth/social/google/callback')
        ->assertOk()
        ->assertJsonPath('data.token', fn ($t) => is_string($t) && strlen($t) > 10);

    expect(User::where('email', 'pc5@gmail.com')->exists())->toBeTrue();
});

it('returns 404 from /social/complete when the feature is disabled', function (): void {
    config(['auth_system.social.profile_completion.enabled' => false]);

    $this->postJson('/auth/social/complete', [
        'completion_token' => str_repeat('a', 36),
        'phone'            => '+14155550111',
    ])->assertStatus(404);
});
