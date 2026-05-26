<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Joe404\LaravelAuth\Database\Seeders\AuthRolesSeeder;

class InstallCommand extends Command
{
    protected $signature = 'auth:install
        {--force : Overwrite existing published files}
        {--skip-migrations : Publish only; do not run migrations}
        {--skip-seed : Skip the AuthRolesSeeder step}
        {--upgrade : Run only new migrations for an existing install (e.g. v2.5 → v2.6)}';

    protected $description = 'One-shot installer: publishes config, dependency migrations, runs them, seeds roles, and wires the Reverb channel. Use --upgrade for in-place version bumps.';

    public function handle(): int
    {
        if ($this->option('upgrade')) {
            return $this->handleUpgrade();
        }

        $this->components->info('Installing joe-404/laravel-auth');

        if (! $this->verifyRequiredPackages()) {
            return self::FAILURE;
        }

        $this->publishConfig();
        $this->publishDependencyMigrations();

        if (! $this->option('skip-migrations')) {
            $this->runMigrations();
        } else {
            $this->components->warn('Skipping migrations (--skip-migrations). Run `php artisan migrate` manually.');
        }

        if (! $this->option('skip-seed')) {
            $this->seedRoles();
        } else {
            $this->components->warn('Skipping role seeding (--skip-seed). Run the seeder manually when ready.');
        }

        $this->wireReverbChannel();
        $this->reportOptionalDependencies();
        $this->printNextSteps();

        return self::SUCCESS;
    }

    /**
     * Confirm the required Composer dependencies are present before we touch
     * the filesystem or the database. Without these the package cannot run
     * at all, so we fail loud here with the exact composer command needed.
     */
    private function verifyRequiredPackages(): bool
    {
        $required = [
            'laravel/sanctum'             => \Laravel\Sanctum\SanctumServiceProvider::class,
            'spatie/laravel-permission'   => \Spatie\Permission\PermissionServiceProvider::class,
            'laravel/socialite'           => \Laravel\Socialite\SocialiteServiceProvider::class,
        ];

        $missing = [];

        foreach ($required as $package => $class) {
            if (! class_exists($class)) {
                $missing[] = $package;
            }
        }

        if ($missing !== []) {
            $this->components->error(
                "Required Composer package(s) missing: " . implode(', ', $missing) . "\n"
                . "Run:  composer require " . implode(' ', $missing)
            );

            return false;
        }

        return true;
    }

    /**
     * Upgrade path for hosts already running an older version. Runs only the
     * version-stamped v2.6 migrations and prints a changelog of new features.
     * Skips publishing dependency migrations (they were published on first install).
     */
    private function handleUpgrade(): int
    {
        $this->components->info('Upgrading joe-404/laravel-auth to v2.6');

        if (! $this->verifyRequiredPackages()) {
            return self::FAILURE;
        }

        $this->components->info('Running new v2.6 migrations…');
        $this->call('migrate', ['--force' => true]);

        $this->newLine();
        $this->components->info('Upgrade complete. v2.6 changelog:');
        $this->line('  • Phone capture at registration + verification via driver (sms/voice/whatsapp)');
        $this->line('  • Two-Factor Authentication (TOTP, Email, SMS) + backup codes');
        $this->line('  • Login challenge flow: challenge_token → /auth/2fa/challenge → real token');
        $this->line('  • Trusted devices with time-based level progression (low/medium/high)');
        $this->line('  • Require2FA middleware ("auth.2fa") with GitHub-style sudo mode fallback');
        $this->line('  • Request::authContext() — read 2FA + trust state from anywhere');
        $this->newLine();
        $this->line('Next steps:');
        $this->line('  1. Add `phone`, `phone_verified_at`, `two_factor_required` to your User $fillable.');
        $this->line('  2. Configure providers (.env): AUTH_PHONE_SMS_PROVIDER=infobip + INFOBIP_API_KEY.');
        $this->line('  3. Re-publish config to see the new sections: vendor:publish --tag=auth-config --force');
        $this->line('  4. See the v2.6 section in docs/upgrading.md for the full guide.');
        $this->newLine();

        return self::SUCCESS;
    }

    private function publishConfig(): void
    {
        $args = ['--tag' => 'auth-config'];
        if ($this->option('force')) {
            $args['--force'] = true;
        }

        $this->callSilent('vendor:publish', $args);
        $this->components->info('Config published to config/auth_system.php');
    }

    /**
     * Publish both Sanctum's and Spatie Permission's migration stubs into the
     * host app's database/migrations directory. The package's own migrations
     * load directly from src/ via loadMigrationsFrom() — no publish needed.
     */
    private function publishDependencyMigrations(): void
    {
        // Sanctum
        $sanctumArgs = ['--provider' => 'Laravel\\Sanctum\\SanctumServiceProvider'];
        if ($this->option('force')) {
            $sanctumArgs['--force'] = true;
        }
        $this->callSilent('vendor:publish', $sanctumArgs);

        // Spatie Permission
        $spatieArgs = [
            '--provider' => 'Spatie\\Permission\\PermissionServiceProvider',
            '--tag'      => 'permission-migrations',
        ];
        if ($this->option('force')) {
            $spatieArgs['--force'] = true;
        }
        $this->callSilent('vendor:publish', $spatieArgs);

        $this->components->info('Sanctum + Spatie Permission migrations published');
    }

    private function runMigrations(): void
    {
        $this->components->info('Running migrations…');
        $this->call('migrate', ['--force' => true]);
    }

    private function seedRoles(): void
    {
        if (! Schema::hasTable('roles')) {
            $this->components->warn(
                'Skipping role seeding — the `roles` table does not exist yet. '
                . 'Run `php artisan migrate` first, then re-run `php artisan auth:install`.'
            );

            return;
        }

        $this->call('db:seed', ['--class' => AuthRolesSeeder::class, '--force' => true]);
        $this->components->info('Default roles seeded');
    }

    /**
     * Append the auth.verification.{tempToken} channel stub to
     * routes/channels.php so Reverb subscribers can listen for real-time
     * email-verification events. Idempotent.
     */
    private function wireReverbChannel(): void
    {
        $channelsPath = base_path('routes/channels.php');
        $stubPath     = __DIR__ . '/../../stubs/channels.stub';

        if (! file_exists($stubPath)) {
            return;
        }

        if (! file_exists($channelsPath)) {
            file_put_contents(
                $channelsPath,
                "<?php\n\nuse Illuminate\\Support\\Facades\\Broadcast;\n",
            );
        }

        $current = (string) file_get_contents($channelsPath);

        if (str_contains($current, 'auth.verification.{tempToken}')) {
            $this->components->info('Reverb channel already present in routes/channels.php');

            return;
        }

        file_put_contents($channelsPath, "\n" . file_get_contents($stubPath), FILE_APPEND);
        $this->components->info('Reverb channel appended to routes/channels.php');
    }

    private function reportOptionalDependencies(): void
    {
        $optional = [
            'Laravel Reverb'    => \Laravel\Reverb\ReverbServiceProvider::class,
            'Laravel Horizon'   => \Laravel\Horizon\HorizonServiceProvider::class,
            'Laravel Telescope' => \Laravel\Telescope\TelescopeServiceProvider::class,
        ];

        $missing = array_keys(array_filter($optional, fn (string $cls) => ! class_exists($cls)));

        if ($missing === []) {
            return;
        }

        $this->newLine();
        $this->components->warn('Optional packages not installed: ' . implode(', ', $missing));
        $this->line('  These are not required, but unlock features:');
        $this->line('    laravel/reverb     → real-time email-verification events');
        $this->line('    laravel/horizon    → dashboard for the auth-maintenance queue');
        $this->line('    laravel/telescope  → request inspector during development');
    }

    private function printNextSteps(): void
    {
        $this->newLine();
        $this->components->info('joe-404/laravel-auth installed.');
        $this->newLine();
        $this->line('Next steps:');
        $this->line('  1. Add `HasRoles` (Spatie Permission), `HasApiTokens` (Sanctum) and `SoftDeletes` traits to your User model.');
        $this->line('     (SoftDeletes enables the v2.4 grace-period deletion + auto-restore-on-login feature.)');
        $this->line('  2. Set `AUTH_MODE` in .env  → api | web | both');
        $this->line('  3. Configure your mail driver — OTP and magic links are delivered by email.');
        $this->line('  4. Add your frontend domain to `config/sanctum.php` stateful list (SPA mode).');
        $this->newLine();
        $this->line('Optional:');
        $this->line('  • Reverb real-time:  AUTH_REVERB_ENABLED=true && php artisan reverb:start');
        $this->line('  • Google OAuth:      AUTH_GOOGLE_ENABLED=true + GOOGLE_CLIENT_* env vars');
        $this->line('  • Translations:      php artisan vendor:publish --tag=auth-lang');
        $this->newLine();
    }
}
