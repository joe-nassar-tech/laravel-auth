<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
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
use Joe404\LaravelAuth\Http\Middleware\DeviceFingerprint;
use Joe404\LaravelAuth\Http\Middleware\FeatureFlag;
use Joe404\LaravelAuth\Http\Middleware\RejectRefreshToken;
use Joe404\LaravelAuth\Http\Middleware\RateLimitAuth;
use Joe404\LaravelAuth\Http\Middleware\RequireActiveAccount;
use Joe404\LaravelAuth\Http\Middleware\RequireEmailVerified;
use Joe404\LaravelAuth\Jobs\CleanExpiredApiTokens;
use Joe404\LaravelAuth\Jobs\CleanExpiredOtpRecords;
use Joe404\LaravelAuth\Jobs\CleanExpiredRefreshTokens;
use Joe404\LaravelAuth\Jobs\PurgeExpiredAccountDeletions;
use Joe404\LaravelAuth\Jobs\RevertExpiredAccountStatuses;
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

        // Bind custom register request class (Option B) when configured
        $customRequest = config('auth_system.registration.request_class');
        if ($customRequest !== null && class_exists((string) $customRequest)) {
            $this->app->bind(
                \Joe404\LaravelAuth\Http\Requests\RegisterRequest::class,
                (string) $customRequest,
            );
        }

        // Bind OTP channel contract
        $this->app->bind(OtpChannelContract::class, function (): OtpChannelContract {
            $driver = (string) config('auth_system.otp_channel.driver', 'email');

            if ($driver !== 'email' && class_exists($driver)) {
                return $this->app->make($driver);
            }

            return $this->app->make(EmailOtpChannel::class);
        });

        // Bind referral code generator (lets host apps swap in their own format)
        $this->app->bind(\Joe404\LaravelAuth\Contracts\ReferralCodeGeneratorContract::class, function () {
            $custom = config('auth_system.referral_code.generator');

            if (is_string($custom) && class_exists($custom)) {
                return $this->app->make($custom);
            }

            return $this->app->make(\Joe404\LaravelAuth\Services\DefaultReferralCodeGenerator::class);
        });

        // Bind referral reward handler. When the developer points the config
        // at their own class we resolve it from the container so they can
        // typehint dependencies (their own services, mailers, etc.). Leaving
        // it null is supported — in that case ReferralService skips the
        // handler entirely and the host listens to events instead.
        $this->app->bind(\Joe404\LaravelAuth\Contracts\ReferralRewardHandlerContract::class, function () {
            $custom = config('auth_system.referral_code.reward_handler');

            if (is_string($custom) && $custom !== '' && class_exists($custom)) {
                return $this->app->make($custom);
            }

            // No-op handler so consumers can typehint the contract without
            // null-checking. ReferralService bypasses this path when the
            // config is null, so this binding is mostly a typehint fallback.
            return new class implements \Joe404\LaravelAuth\Contracts\ReferralRewardHandlerContract {
                public function handle(\Joe404\LaravelAuth\Models\Referral $referral): void
                {
                    // intentional no-op
                }
            };
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
        $this->validateConfig();
        $this->ensureApiRateLimiter();
        $this->publishAssets();
        $this->configureSocialite();
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'laravel-auth');
        $this->loadTranslationsFrom(__DIR__ . '/../resources/lang', 'auth_system');
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->registerRoutes();
        $this->registerMiddleware();
        $this->registerCommands();
        $this->registerSchedule();
        $this->registerEvents();
        $this->registerExceptionHandlers();
    }

    /**
     * Host apps that follow the standard Laravel skeleton already register a
     * named "api" rate limiter via RouteServiceProvider. In bare package
     * contexts (and tests) it does not exist — register a sensible default so
     * the package's "throttle:api" middleware does not blow up.
     */
    private function ensureApiRateLimiter(): void
    {
        if (RateLimiter::limiter('api') === null) {
            RateLimiter::for('api', fn (Request $request) => Limit::perMinute(60)->by(
                $request->user()?->getKey() ?? $request->ip(),
            ));
        }
    }

    private function validateConfig(): void
    {
        $otpLength = (int) config('auth_system.verification.otp_length', 6);

        if ($otpLength < 4 || $otpLength > 8) {
            throw new \InvalidArgumentException(
                "auth_system.verification.otp_length must be between 4 and 8, got {$otpLength}.",
            );
        }
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

            $this->publishes([
                __DIR__ . '/../resources/views' => resource_path('views/vendor/laravel-auth'),
            ], 'auth-views');

            // Laravel 9+ uses lang/ at the project root; older skeletons used
            // resources/lang. Publish to whichever the host app has so the
            // standard `lang/vendor/auth_system/<locale>` lookup works.
            $langTarget = is_dir(base_path('lang'))
                ? base_path('lang/vendor/auth_system')
                : resource_path('lang/vendor/auth_system');

            $this->publishes([
                __DIR__ . '/../resources/lang' => $langTarget,
            ], 'auth-lang');
        }
    }

    private function registerRoutes(): void
    {
        // Host apps that mount the package routes manually (e.g. inside a
        // versioned API group like /api/v1/auth) can flip this to false and
        // require __DIR__.'/../routes/auth.php' themselves with whatever
        // prefix/middleware they want.
        if (! (bool) config('auth_system.routes.register', true)) {
            return;
        }

        $mode = (string) config('auth_system.mode', 'both');

        if ($mode === 'api') {
            $defaultMiddleware = ['api'];
        } else {
            // Session middleware without the full 'web' group so we can swap
            // VerifyCsrfToken for ConditionalCsrf, which skips CSRF for Bearer
            // token requests (mobile / API clients) while still protecting
            // session-based (SPA) requests.
            $defaultMiddleware = [
                \Illuminate\Cookie\Middleware\EncryptCookies::class,
                \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
                \Illuminate\Session\Middleware\StartSession::class,
                \Illuminate\View\Middleware\ShareErrorsFromSession::class,
                \Joe404\LaravelAuth\Http\Middleware\ConditionalCsrf::class,
                'api',
            ];
        }

        $prefix     = (string) config('auth_system.routes.prefix', 'auth');
        $middleware = config('auth_system.routes.middleware') ?: $defaultMiddleware;

        Route::prefix($prefix)
            ->middleware($middleware)
            ->group(__DIR__ . '/../routes/auth.php');
    }

    private function registerMiddleware(): void
    {
        /** @var Router $router */
        $router = $this->app->make(Router::class);

        $router->aliasMiddleware('auth.ratelimit', RateLimitAuth::class);
        $router->aliasMiddleware('auth.verified', RequireEmailVerified::class);
        $router->aliasMiddleware('auth.no-refresh', RejectRefreshToken::class);
        $router->aliasMiddleware('auth.device', DeviceFingerprint::class);
        $router->aliasMiddleware('auth.api-token', ApiTokenAuth::class);
        $router->aliasMiddleware('auth.feature', FeatureFlag::class);
        $router->aliasMiddleware('auth.active', RequireActiveAccount::class);

        // Spatie's PermissionServiceProvider stopped auto-registering middleware
        // aliases in Laravel 11. We register them here so package routes
        // ("role:super-admin|admin") work without the host app having to add
        // them to bootstrap/app.php.
        if (! $router->hasMiddlewareGroup('role')) {
            if (class_exists(\Spatie\Permission\Middleware\RoleMiddleware::class)) {
                $router->aliasMiddleware('role', \Spatie\Permission\Middleware\RoleMiddleware::class);
            }
            if (class_exists(\Spatie\Permission\Middleware\PermissionMiddleware::class)) {
                $router->aliasMiddleware('permission', \Spatie\Permission\Middleware\PermissionMiddleware::class);
            }
            if (class_exists(\Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class)) {
                $router->aliasMiddleware('role_or_permission', \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class);
            }
        }
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

            $schedule->job(CleanExpiredRefreshTokens::class, $queue)
                ->hourly()
                ->name('auth-clean-expired-refresh-tokens')
                ->withoutOverlapping();

            if ((bool) config('auth_system.api_tokens.enabled', false)) {
                $schedule->job(CleanExpiredApiTokens::class, $queue)
                    ->hourly()
                    ->name('auth-clean-expired-api-tokens')
                    ->withoutOverlapping();
            }

            if ((bool) config('auth_system.account.deletion.enabled', true)) {
                $schedule->job(PurgeExpiredAccountDeletions::class, $queue)
                    ->hourly()
                    ->name('auth-purge-expired-account-deletions')
                    ->withoutOverlapping();
            }

            if ((bool) config('auth_system.account.status.auto_unban.enabled', true)) {
                $sweepMinutes = max(1, (int) config('auth_system.account.status.auto_unban.sweep_minutes', 5));

                $schedule->job(RevertExpiredAccountStatuses::class, $queue)
                    ->cron("*/{$sweepMinutes} * * * *")
                    ->name('auth-revert-expired-account-statuses')
                    ->withoutOverlapping();
            }
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

            $message = (string) config('auth_system.errors.unauthenticated')
                ?: (function (): string {
                    $t = trans('auth_system::errors.unauthenticated');

                    return (is_string($t) && $t !== 'auth_system::errors.unauthenticated' && $t !== '')
                        ? $t
                        : 'Unauthenticated.';
                })();

            return response()->json(
                $formatter->format(false, $message, [], []),
                401,
            );
        });
    }

    private function isAuthRoute(\Illuminate\Http\Request $request): bool
    {
        $prefix = trim((string) config('auth_system.routes.prefix', 'auth'), '/');

        if ($prefix === '') {
            // Routes mounted at the root: every request is potentially an
            // auth route. Fall back to checking against the named-route
            // table so the envelope only wraps actual package routes.
            $route = $request->route();

            return $route !== null
                && is_string($route->getName())
                && str_starts_with($route->getName(), 'auth.');
        }

        $path = $request->path();

        return $path === $prefix || str_starts_with($path, $prefix . '/');
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
