<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Crypt;
use Joe404\LaravelAuth\Models\AuthSocialAccount;
use Joe404\LaravelAuth\Models\AuthTwoFactorMethod;
use Joe404\LaravelAuth\Services\TotpService;
use Joe404\LaravelAuth\Tests\Fixtures\User;
use Laravel\Socialite\Facades\Socialite;

function secSocialUser(string $id, string $email): object
{
    return new class ($id, $email) {
        public function __construct(private string $id, private string $email) {}
        public function getId(): string { return $this->id; }
        public function getEmail(): string { return $this->email; }
        public function getName(): string { return 'Sec User'; }
        public function getAvatar(): ?string { return null; }
    };
}

function secMockSocialite(object $socialUser): void
{
    $provider = Mockery::mock(\Laravel\Socialite\Contracts\Provider::class);
    $provider->shouldReceive('stateless')->andReturnSelf();
    $provider->shouldReceive('user')->andReturn($socialUser);
    Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
}

beforeEach(function (): void {
    \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'user', 'guard_name' => 'web']);
    config([
        'auth_system.social.google.enabled' => true,
        'auth_system.two_factor.enabled'    => true,
    ]);
});

afterEach(fn () => Mockery::close());

/** Link an existing user to a google social account so callback hits branch 1. */
function secLinkSocial(User $user, string $providerId): void
{
    AuthSocialAccount::create([
        'user_id'        => $user->getKey(),
        'provider'       => 'google',
        'provider_id'    => $providerId,
        'provider_email' => $user->email,
        'avatar'         => null,
    ]);
}

function secEnrollTotp(User $user): void
{
    AuthTwoFactorMethod::create([
        'user_id'          => $user->getKey(),
        'type'             => 'totp',
        'secret_encrypted' => Crypt::encryptString((new TotpService())->generateSecret()),
        'is_default'       => true,
        'verified_at'      => now(),
    ]);
}

it('#1 social login returns a 2FA challenge (no token) when the linked user has 2FA enrolled', function (): void {
    $user = $this->createUser(['email' => 'social2fa@example.com']);
    secEnrollTotp($user);
    secLinkSocial($user, 'goog_2fa_1');

    secMockSocialite(secSocialUser('goog_2fa_1', 'social2fa@example.com'));

    $response = $this->getJson('/auth/social/google/callback');

    $response->assertOk()
        ->assertJsonPath('data.requires_2fa', true)
        ->assertJsonStructure(['data' => ['challenge_token', 'method', 'available_methods']]);

    expect($response->json('data.token'))->toBeNull();
});

it('#2 social login is rejected for a suspended account', function (): void {
    config(['auth_system.account.status.enabled' => true]);

    $user = $this->createUser([
        'email'          => 'socialsusp@example.com',
        'account_status' => 'suspended',
    ]);
    secLinkSocial($user, 'goog_susp_1');

    secMockSocialite(secSocialUser('goog_susp_1', 'socialsusp@example.com'));

    $response = $this->getJson('/auth/social/google/callback');

    $response->assertStatus(401)->assertJsonPath('success', false);
    expect($response->json('data.token'))->toBeNull();
});

it('#2 social login still works for an active linked account (no 2FA)', function (): void {
    $user = $this->createUser(['email' => 'socialok@example.com']);
    secLinkSocial($user, 'goog_ok_1');

    secMockSocialite(secSocialUser('goog_ok_1', 'socialok@example.com'));

    $this->getJson('/auth/social/google/callback')
        ->assertOk()
        ->assertJsonPath('data.token', fn ($t) => is_string($t) && strlen($t) > 10);
});
