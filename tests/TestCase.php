<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Joe404\LaravelAuth\AuthServiceProvider;
use Joe404\LaravelAuth\Tests\Fixtures\User;
use Laravel\Sanctum\SanctumServiceProvider;
use Laravel\Socialite\SocialiteServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Spatie\Permission\PermissionServiceProvider;

abstract class TestCase extends OrchestraTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Bind the test User model
        $this->app->bind(
            \Illuminate\Foundation\Auth\User::class,
            User::class,
        );
    }

    /**
     * @param  \Illuminate\Foundation\Application  $app
     * @return array<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            SanctumServiceProvider::class,
            PermissionServiceProvider::class,
            SocialiteServiceProvider::class,
            AuthServiceProvider::class,
        ];
    }

    /**
     * @param  \Illuminate\Foundation\Application  $app
     */
    protected function getEnvironmentSetUp($app): void
    {
        // Use SQLite in-memory for tests
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);

        // Use array cache for tests
        $app['config']->set('cache.default', 'array');

        // Use array queue for tests
        $app['config']->set('queue.default', 'sync');

        // Use array session for tests
        $app['config']->set('session.driver', 'array');

        // Use log mail for tests
        $app['config']->set('mail.default', 'log');

        // Point auth to test User model
        $app['config']->set('auth.providers.users.model', User::class);

        // Auth system config for tests
        $app['config']->set('auth_system.mode', 'api');
        $app['config']->set('auth_system.verification.method', 'both');
        $app['config']->set('auth_system.verification.otp_length', 6);
        $app['config']->set('auth_system.verification.otp_expiry', 10);
        $app['config']->set('auth_system.verification.magic_expiry', 30);
        $app['config']->set('auth_system.require_email_verification', true);
        $app['config']->set('auth_system.roles.default_role', 'user');
        $app['config']->set('auth_system.roles.seeded_roles', ['super-admin', 'admin', 'user']);
        $app['config']->set('auth_system.rate_limits.register', '5:1');
        $app['config']->set('auth_system.rate_limits.login', '5:1');
        $app['config']->set('auth_system.token.expiration_minutes', 10080);
        $app['config']->set('auth_system.reverb.enabled', false);

        // Sanctum config
        $app['config']->set('sanctum.stateful', []);
    }

    protected function defineDatabaseMigrations(): void
    {
        // Create the host-app users table BEFORE the package migrations run,
        // so the package's "alter users add columns" migration has a target.
        $this->loadMigrationsFrom(__DIR__ . '/Fixtures/migrations');

        // Package migrations (OTP codes, sessions, refresh tokens, etc).
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        // Spatie permission migrations — registered like the others so they
        // participate in RefreshDatabase rollback/replay.
        $this->loadMigrationsFrom(__DIR__ . '/../vendor/spatie/laravel-permission/database/migrations');

        // Sanctum.
        $this->loadMigrationsFrom(__DIR__ . '/../vendor/laravel/sanctum/database/migrations');
    }

    /**
     * Create a test user directly without factory.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function createUser(array $overrides = []): User
    {
        return User::create(array_merge([
            'name'              => 'Test User',
            'email'             => 'test@example.com',
            'password'          => bcrypt('password'),
            'email_verified_at' => now(),
            'is_active'         => true,
        ], $overrides));
    }

    /**
     * Create a verified user and return an auth token.
     */
    protected function createAuthenticatedUser(array $overrides = []): array
    {
        $user  = $this->createUser($overrides);
        $token = $user->createToken('auth-token')->plainTextToken;

        return ['user' => $user, 'token' => $token];
    }
}
