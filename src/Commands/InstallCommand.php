<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Commands;

use Illuminate\Console\Command;
use Joe404\LaravelAuth\Database\Seeders\AuthRolesSeeder;

class InstallCommand extends Command
{
    protected $signature = 'auth:install';

    protected $description = 'Install the joe-404/laravel-auth package.';

    public function handle(): void
    {
        $this->info('Installing joe-404/laravel-auth...');

        // 1. Publish config
        $this->callSilent('vendor:publish', ['--tag' => 'auth-config']);
        $this->info('[✓] Config published.');

        // 2. Run migrations
        $this->call('migrate');
        $this->info('[✓] Migrations complete.');

        // 3. Seed roles
        $this->call('db:seed', ['--class' => AuthRolesSeeder::class]);
        $this->info('[✓] Roles seeded.');

        // 4. Publish Reverb channel authorization stub
        $channelsPath = base_path('routes/channels.php');
        $stub         = __DIR__ . '/../../stubs/channels.stub';

        if (file_exists($stub)) {
            if (! file_exists($channelsPath)) {
                // Create channels.php if it doesn't exist
                file_put_contents($channelsPath, "<?php\n\nuse Illuminate\\Support\\Facades\\Broadcast;\n");
            }

            $currentContent = file_get_contents($channelsPath);
            $stubContent    = file_get_contents($stub);

            // Only append if not already present
            if (! str_contains($currentContent, 'auth.verification.{tempToken}')) {
                file_put_contents($channelsPath, "\n" . $stubContent, FILE_APPEND);
                $this->info('[✓] Reverb channel authorization stub appended to routes/channels.php');
            } else {
                $this->info('[✓] Reverb channel authorization already present in routes/channels.php');
            }
        }

        // 5. Check optional dependencies
        $optionalPackages = [
            'Laravel Horizon'    => \Laravel\Horizon\HorizonServiceProvider::class,
            'Laravel Reverb'     => \Laravel\Reverb\ReverbServiceProvider::class,
            'Laravel Telescope'  => \Laravel\Telescope\TelescopeServiceProvider::class,
        ];

        foreach ($optionalPackages as $name => $class) {
            if (! class_exists($class)) {
                $this->warn("[!] Optional package not found: {$name}. Install it for full functionality.");
            }
        }

        // 6. Next steps
        $this->newLine();
        $this->info('✅ joe-404/laravel-auth installed successfully!');
        $this->newLine();
        $this->line('Next steps:');
        $this->line('  1. Set AUTH_MODE in .env                    → api | web | both');
        $this->line('  2. Configure mail driver                    → for OTP + magic link delivery');
        $this->line('  3. Set AUTH_VERIFY_METHOD in .env           → otp | magic_link | both');
        $this->line('  4. Add HasRoles trait to your User model    → (Spatie Permission)');
        $this->line('  5. Add HasApiTokens trait to your User model → (Sanctum)');
        $this->newLine();
        $this->line('  Optional features:');
        $this->line('  6. AUTH_GOOGLE_ENABLED=true                 → configure GOOGLE_CLIENT_* in .env');
        $this->line('  7. AUTH_REVERB_ENABLED=true                 → run: php artisan reverb:start');
        $this->line('     Review routes/channels.php               → Reverb channel auth was appended');
        $this->line('  8. Install Horizon                          → run: php artisan horizon:install');
        $this->line('     Queue: auth-maintenance                  → handles cleanup jobs');
        $this->newLine();
    }
}
