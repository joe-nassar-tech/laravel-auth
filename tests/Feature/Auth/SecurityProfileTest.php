<?php

declare(strict_types=1);

use Joe404\LaravelAuth\AuthServiceProvider;

beforeEach(function (): void {
    \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'user', 'guard_name' => 'web']);

    // Clear any env vars the profile considers so its "env-not-set → fill in"
    // logic is deterministic regardless of CI's ambient environment.
    foreach ([
        'AUTH_API_TOKENS_STRICT',
        'AUTH_API_TOKENS_REQUIRE_STEP_UP',
        'AUTH_API_TOKENS_ADMIN_REQUIRE_STEP_UP',
        'AUTH_SOCIAL_ENFORCE_STATE',
        'AUTH_LOCKOUT_SCOPE',
        'AUTH_PASSWORD_RESET_AUTO_LOGIN',
        'AUTH_ACCOUNT_STATUS_HIERARCHY',
        'AUTH_ACCOUNT_STATUS_REQUIRE_STEP_UP',
        'AUTH_TRUST_REG_DEVICE_LEVEL',
        'AUTH_2FA_REQUIRED',
    ] as $k) {
        putenv($k);
        unset($_ENV[$k], $_SERVER[$k]);
    }
});

function invokeApplySecurityProfile(): void
{
    $provider = new AuthServiceProvider(app());
    $m        = new ReflectionMethod($provider, 'applySecurityProfile');
    $m->setAccessible(true);
    $m->invoke($provider);
}

it('high profile flips the hardening flags when no env override is set', function (): void {
    // Start from v2.7 defaults so we can prove the profile flipped them.
    config([
        'auth_system.security.profile'                => 'high',
        'auth_system.api_tokens.strict_abilities'     => false,
        'auth_system.api_tokens.require_step_up'      => false,
        'auth_system.social.enforce_state'            => false,
        'auth_system.security.lockout.scope'          => 'email',
        'auth_system.password_reset.auto_login'       => true,
        'auth_system.two_factor.required'             => false,
    ]);

    invokeApplySecurityProfile();

    expect(config('auth_system.api_tokens.strict_abilities'))->toBeTrue();
    expect(config('auth_system.api_tokens.require_step_up'))->toBeTrue();
    expect(config('auth_system.social.enforce_state'))->toBeTrue();
    expect(config('auth_system.security.lockout.scope'))->toBe('email_and_ip');
    expect(config('auth_system.password_reset.auto_login'))->toBeFalse();
    expect(config('auth_system.two_factor.required'))->toBeTrue();
});

it('null profile is a no-op (developer-only configuration)', function (): void {
    config([
        'auth_system.security.profile'             => null,
        'auth_system.api_tokens.strict_abilities'  => false,
    ]);

    invokeApplySecurityProfile();

    expect(config('auth_system.api_tokens.strict_abilities'))->toBeFalse();
});

it('relaxed profile is a no-op (matches the v2.7 defaults)', function (): void {
    config([
        'auth_system.security.profile'             => 'relaxed',
        'auth_system.api_tokens.strict_abilities'  => false,
    ]);

    invokeApplySecurityProfile();

    expect(config('auth_system.api_tokens.strict_abilities'))->toBeFalse();
});

it('explicit env value beats the profile (developer freedom)', function (): void {
    // Developer explicitly sets the env var → profile must NOT override it.
    putenv('AUTH_API_TOKENS_STRICT=false');
    $_ENV['AUTH_API_TOKENS_STRICT']    = 'false';
    $_SERVER['AUTH_API_TOKENS_STRICT'] = 'false';

    config([
        'auth_system.security.profile'             => 'high',
        // What the config file would have computed from the env var.
        'auth_system.api_tokens.strict_abilities'  => false,
        // A second flag whose env is NOT set — profile should still flip it.
        'auth_system.social.enforce_state'         => false,
    ]);

    invokeApplySecurityProfile();

    expect(config('auth_system.api_tokens.strict_abilities'))->toBeFalse(); // env wins
    expect(config('auth_system.social.enforce_state'))->toBeTrue();          // profile filled

    // Cleanup so the env doesn't leak into later tests.
    putenv('AUTH_API_TOKENS_STRICT');
    unset($_ENV['AUTH_API_TOKENS_STRICT'], $_SERVER['AUTH_API_TOKENS_STRICT']);
});
