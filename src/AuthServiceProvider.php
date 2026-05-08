<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\ValidationException;
use Joe404\LaravelAuth\Channels\EmailOtpChannel;
use Joe404\LaravelAuth\Commands\InstallCommand;
use Joe404\LaravelAuth\Contracts\OtpChannelContract;
use Joe404\LaravelAuth\Contracts\ResponseFormatterContract;
use Joe404\LaravelAuth\Events\SuspiciousLoginDetected;
use Joe404\LaravelAuth\Events\UserRegistered;
use Joe404\LaravelAuth\Http\Formatters\DefaultResponseFormatter;
use Joe404\LaravelAuth\Http\Middleware\ApiTokenAuth;
use Joe404\LaravelAuth\Http\Middleware\AuthMode;
use Joe404\LaravelAuth\Http\Middleware\DeviceFingerprint;
use Joe404\LaravelAuth\Http\Middleware\RateLimitAuth;
use Joe404\LaravelAuth\Http\Middleware\RequireEmailVerified;
use Joe404\LaravelAuth\Jobs\CleanExpiredApiTokens;
use Joe404\LaravelAuth\Jobs\CleanExpiredOtpRecords;
use Joe404\LaravelAuth\Listeners\NotifySuspiciousLogin;
use Joe404\LaravelAuth\Listeners\SendVerificationNotification;

class AuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/auth_system.php',
            'auth_system',
        );

        // Bind OTP channel contract
        $this->app->bind(OtpChannelContract::class, function (): OtpChannelContract {
            $driver = (string) config('auth_system.otp_channel.driver', 'email');

            if ($driver !== 'email' && class_exists($driver)) {
                return $this->app->make($driver);
            }

            return $this->app->make(EmailOtpChannel::class);
        });

        // Bind response formatter contract
        $this->app->bind(ResponseFormatterContract::class, function (): ResponseFormatterContract {
            $formatterClass = config('auth_system.response.formatter');

            if ($formatterClass !== null && class_exists((string) $formatterClass)) {
                return $this->app->make((string) $formatterClass);
            }

            return $this->app->make(DefaultResponseFormatter::class);
        });
    }

    public function boot(): void
    {
        $this->publishAssets();
        $this->configureSocialite();
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->registerRoutes();
        $this->registerMiddleware();
        $this->registerCommands();
        $this->registerSchedule();
        $this->registerEvents();
        $this->registerExceptionHandlers();
    }

    private function publishAssets(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/auth_system.php' => config_path('auth_system.php'),
            ], 'auth-config');

            $this->publishes([
                __DIR__ . '/../database/migrations' => database_path('migrations'),
            ], 'auth-migrations');

            $this->publishes([
                __DIR__ . '/Database/Seeders/AuthRolesSeeder.php' => database_path('seeders/AuthRolesSeeder.php'),
            ], 'auth-seeders');

            $this->publishes([
                __DIR__ . '/../stubs/' => base_path('stubs/vendor/joe-404/laravel-auth'),
            ], 'auth-stubs');
        }
    }

    private function registerRoutes(): void
    {
        Route::prefix('auth')
            ->middleware('api')
            ->group(__DIR__ . '/../routes/auth.php');
    }

    private function registerMiddleware(): void
    {
        /** @var Router $router */
        $router = $this->app->make(Router::class);

        $router->aliasMiddleware('auth.ratelimit', RateLimitAuth::class);
        $router->aliasMiddleware('auth.verified', RequireEmailVerified::class);
        $router->aliasMiddleware('auth.mode', AuthMode::class);
        $router->aliasMiddleware('auth.device', DeviceFingerprint::class);
        $router->aliasMiddleware('auth.api-token', ApiTokenAuth::class);
    }

    private function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallCommand::class,
            ]);
        }
    }

    private function registerSchedule(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $queue = (string) config('auth_system.queue.name', 'auth-maintenance');

            $schedule->job(CleanExpiredOtpRecords::class, $queue)
                ->everyFiveMinutes()
                ->name('auth-clean-expired-otps')
                ->withoutOverlapping();

            $schedule->job(CleanExpiredApiTokens::class, $queue)
                ->hourly()
                ->name('auth-clean-expired-api-tokens')
                ->withoutOverlapping();
        });
    }

    private function registerEvents(): void
    {
        Event::listen(UserRegistered::class, SendVerificationNotification::class);
        Event::listen(SuspiciousLoginDetected::class, NotifySuspiciousLogin::class);
    }

    private function registerExceptionHandlers(): void
    {
        $this->app->singleton('auth.exception_handlers_registered', fn () => true);

        $handler = $this->app->make(\Illuminate\Contracts\Debug\ExceptionHandler::class);

        if (! method_exists($handler, 'renderable')) {
            return;
        }

        // Wrap ValidationException in the standard envelope for auth routes
        $handler->renderable(function (ValidationException $e, $request) {
            if (! $this->isAuthRoute($request)) {
                return null;
            }

            /** @var ResponseFormatterContract $formatter */
            $formatter = $this->app->make(ResponseFormatterContract::class);

            return response()->json(
                $formatter->format(false, $e->getMessage(), [], $e->errors()),
                422,
            );
        });

        // Wrap AuthenticationException in the standard envelope for auth routes
        $handler->renderable(function (AuthenticationException $e, $request) {
            if (! $this->isAuthRoute($request)) {
                return null;
            }

            /** @var ResponseFormatterContract $formatter */
            $formatter = $this->app->make(ResponseFormatterContract::class);

            return response()->json(
                $formatter->format(false, 'Unauthenticated.', [], []),
                401,
            );
        });
    }

    private function isAuthRoute(\Illuminate\Http\Request $request): bool
    {
        return str_starts_with($request->path(), 'auth/') || $request->path() === 'auth';
    }

    private function configureSocialite(): void
    {
        if (! config('auth_system.social.google.enabled', false)) {
            return;
        }

        config([
            'services.google' => [
                'client_id'     => config('auth_system.social.google.client_id'),
                'client_secret' => config('auth_system.social.google.client_secret'),
                'redirect'      => config('auth_system.social.google.redirect'),
            ],
        ]);
    }
}
