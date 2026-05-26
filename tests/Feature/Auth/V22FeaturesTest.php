<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Joe404\LaravelAuth\Contracts\ExtraFieldTransformerContract;
use Joe404\LaravelAuth\Contracts\ReferralCodeGeneratorContract;
use Joe404\LaravelAuth\Models\AuthOtpCode;
use Joe404\LaravelAuth\Tests\Fixtures\User;

beforeEach(function (): void {
    Notification::fake();
    Queue::fake();

    \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'user', 'guard_name' => 'web']);
});

function seedOtpV22(string $email, string $rawOtp = '123456'): AuthOtpCode
{
    return AuthOtpCode::create([
        'email'      => $email,
        'type'       => 'email_verify',
        'token'      => hash('sha256', $rawOtp),
        'temp_token' => Str::uuid()->toString(),
        'expires_at' => now()->addMinutes(10),
    ]);
}

/**
 * Drive a full register → verify-otp → complete flow and return the created user.
 */
function completeRegistrationFlow(string $email, array $extraInputs = []): User
{
    config()->set('auth_system.verification.method', 'otp');

    test()->postJson('/auth/register', array_merge(['email' => $email], $extraInputs))->assertStatus(201);

    seedOtpV22($email, '424242');

    $verify = test()->postJson('/auth/register/verify-otp', [
        'email' => $email,
        'otp'   => '424242',
    ])->assertOk();

    test()->postJson('/auth/register/complete', [
        'completion_token'      => $verify->json('data.completion_token'),
        'password'              => 'Password123!',
        'password_confirmation' => 'Password123!',
    ])->assertStatus(201);

    return User::where('email', $email)->firstOrFail();
}

// ────────────────────────────────────────────────────────────────────────────
// 1. Referral codes
// ────────────────────────────────────────────────────────────────────────────

it('does not generate a referral code when the feature is disabled (default)', function (): void {
    config()->set('auth_system.referral_code.enabled', false);

    $user = completeRegistrationFlow('no-ref@example.com');

    expect($user->referral_code)->toBeNull();
});

it('generates a unique referral code on finalizeRegistration when enabled', function (): void {
    config()->set('auth_system.referral_code.enabled', true);
    config()->set('auth_system.referral_code.length', 10);
    config()->set('auth_system.referral_code.uppercase', true);

    $user = completeRegistrationFlow('ref@example.com');

    expect($user->referral_code)->not->toBeNull()
        ->and(strlen($user->referral_code))->toBe(10)
        ->and($user->referral_code)->toBe(strtoupper($user->referral_code));
});

it('does not overwrite a referral code already set on the user (e.g. by a transformer)', function (): void {
    // NOTE: a `referral_code` submitted to /auth/register is interpreted as
    // the INCOMING referrer's code (who referred this user), not the new
    // user's own code — the package extracts and consumes it separately. The
    // supported way to pre-set the user's OWN code is a transformer writing to
    // the referral_code column; finalizeRegistration must then leave it alone.
    config()->set('auth_system.referral_code.enabled', true);
    config()->set('auth_system.registration.extra_fields_transformers', [
        'referral_code' => PresetReferralTransformer::class,
    ]);

    $user = completeRegistrationFlow('preset-ref@example.com');

    expect($user->referral_code)->toBe('KEEPME0001');
});

it('uses a custom generator class when configured', function (): void {
    config()->set('auth_system.referral_code.enabled', true);
    config()->set('auth_system.referral_code.generator', FixedReferralGenerator::class);

    $user = completeRegistrationFlow('custom-gen@example.com');

    expect($user->referral_code)->toBe('FIXED-1234');
});

// ────────────────────────────────────────────────────────────────────────────
// 2. Custom response messages
// ────────────────────────────────────────────────────────────────────────────

it('returns the built-in default message when no override is configured', function (): void {
    config()->set('auth_system.messages.register_initiated', null);

    $response = $this->postJson('/auth/register', ['email' => 'msg-default@example.com'])->assertStatus(201);

    expect($response->json('message'))->toBe('Verification sent. Please check your email.');
});

it('uses the configured message override for register_initiated', function (): void {
    config()->set('auth_system.messages.register_initiated', 'Check your inbox for a code.');

    $response = $this->postJson('/auth/register', ['email' => 'msg-custom@example.com'])->assertStatus(201);

    expect($response->json('message'))->toBe('Check your inbox for a code.');
});

it('uses the configured message override for login_success', function (): void {
    config()->set('auth_system.messages.login_success', 'Welcome back!');

    $this->createUser(['email' => 'login-msg@example.com', 'password' => bcrypt('Password123!')]);

    $response = $this->postJson('/auth/login', [
        'email'    => 'login-msg@example.com',
        'password' => 'Password123!',
    ])->assertOk();

    expect($response->json('message'))->toBe('Welcome back!');
});

it('falls back to default when the override is an empty string', function (): void {
    config()->set('auth_system.messages.register_initiated', '');

    $response = $this->postJson('/auth/register', ['email' => 'empty-override@example.com'])->assertStatus(201);

    expect($response->json('message'))->toBe('Verification sent. Please check your email.');
});

// ────────────────────────────────────────────────────────────────────────────
// 3. Extra-field validation messages
// ────────────────────────────────────────────────────────────────────────────

it('uses configured extra_fields_messages instead of Laravel defaults', function (): void {
    config()->set('auth_system.registration.extra_fields_rules', [
        'username' => 'required|string|min:3',
    ]);
    config()->set('auth_system.registration.extra_fields_messages', [
        'username.required' => 'Pick a username before you continue.',
    ]);

    $response = $this->postJson('/auth/register', ['email' => 'msg-extra@example.com'])
        ->assertStatus(422);

    expect($response->json('errors.username'))->toContain('Pick a username before you continue.');
});

// ────────────────────────────────────────────────────────────────────────────
// 4. Extra-field transformers
// ────────────────────────────────────────────────────────────────────────────

it('applies a transformer to derive a new field from validated input', function (): void {
    config()->set('auth_system.registration.extra_fields_rules', [
        'username' => 'required|string|min:3',
    ]);
    config()->set('auth_system.registration.extra_fields_transformers', [
        'username_normalized' => UsernameLowercaseTransformer::class,
    ]);

    $user = completeRegistrationFlow('tx@example.com', ['username' => 'AliceLastName']);

    expect($user->username)->toBe('AliceLastName')
        ->and($user->username_normalized)->toBe('alicelastname');
});

it('transformer output cannot bypass the privileged-fields denylist', function (): void {
    config()->set('auth_system.registration.extra_fields_transformers', [
        'role' => MaliciousRoleTransformer::class,  // denied target field name
    ]);

    $user = completeRegistrationFlow('priv@example.com');

    expect($user->getAttribute('role'))->not->toBe('admin');
});

// ────────────────────────────────────────────────────────────────────────────
// Test fixtures
// ────────────────────────────────────────────────────────────────────────────

class FixedReferralGenerator implements ReferralCodeGeneratorContract
{
    public function generate(): string
    {
        return 'FIXED-1234';
    }
}

class UsernameLowercaseTransformer implements ExtraFieldTransformerContract
{
    public function transform(array $validated): mixed
    {
        return strtolower(trim((string) ($validated['username'] ?? '')));
    }
}

class PresetReferralTransformer implements ExtraFieldTransformerContract
{
    public function transform(array $validated): mixed
    {
        return 'KEEPME0001';
    }
}

class MaliciousRoleTransformer implements ExtraFieldTransformerContract
{
    public function transform(array $validated): mixed
    {
        return 'admin';
    }
}
