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
use Joe404\LaravelAuth\Http\Middleware\AdminGate;
use Joe404\LaravelAuth\Http\Middleware\ApiTokenAuth;
use Joe404\LaravelAuth\Http\Middleware\DeviceFingerprint;
use Joe404\LaravelAuth\Http\Middleware\EnforceRequired2FA;
use Joe404\LaravelAuth\Http\Middleware\FeatureFlag;
use Joe404\LaravelAuth\Http\Middleware\RejectRefreshToken;
use Joe404\LaravelAuth\Http\Middleware\RateLimitAuth;
use Joe404\LaravelAuth\Http\Middleware\Require2FA;
use Joe404\LaravelAuth\Http\Middleware\RequireActiveAccount;
use Joe404\LaravelAuth\Http\Middleware\RequireEmailVerified;
use Joe404\LaravelAuth\Http\Middleware\RequireStepUp;
use Joe404\LaravelAuth\Http\Middleware\RequireStepUpForApiTokenCreation;
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

        // v2.6 — Phone driver manager (singleton: caches resolved providers
        // and remembers host-registered custom drivers across requests).
        $this->app->singleton(\Joe404\LaravelAuth\Phone\PhoneDriverManager::class, function ($app) {
            return new \Joe404\LaravelAuth\Phone\PhoneDriverManager($app);
        });
    }

    public function boot(): void
    {
        $this->applySecurityProfile();
        $this->validateConfig();
        $this->ensureApiRateLimiter();
        $this->publishAssets();
        $this->configureSocialite();
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'laravel-auth');
        $this->loadTranslationsFrom(__DIR__ . '/../resources/lang', 'auth_system');
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->registerRoutes();
        $this->registerMiddleware();
        $this->registerRequestMacros();
        $this->registerCommands();
        $this->registerSchedule();
        $this->registerEvents();
        $this->registerExceptionHandlers();
    }

    /**
     * v2.6 — Expose Request::authContext() so host code (policies, middleware,
     * controllers) can inspect 2FA + trust-level state without coupling to the
     * services directly. Read-only snapshot of session/token + cache.
     */
    private function registerRequestMacros(): void
    {
        if (! \Illuminate\Http\Request::hasMacro('authContext')) {
            \Illuminate\Http\Request::macro('authContext', function () {
                /** @var \Illuminate\Http\Request $this */
                return \Joe404\LaravelAuth\Support\AuthContext::forRequest($this);
            });
        }
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

        // #14 — enforce the NIST SP 800-63B hard floor for password length.
        // The default is 15; a host may lower it via AUTH_PASSWORD_MIN, but
        // not below 8.
        $minLength = (int) config('auth_system.password.min_length', 15);

        if ($minLength < 8) {
            throw new \InvalidArgumentException(
                "auth_system.password.min_length must be at least 8 (NIST 800-63B floor), got {$minLength}.",
            );
        }

        // The OTP and backup-code HMAC pepper (and 2FA secret encryption via
        // Crypt) derive from APP_KEY. An empty key silently degrades OTP hashing
        // to an unsalted digest, making the low-entropy numeric code space
        // brute-forceable from a database leak. APP_KEY is required by Laravel
        // for encryption anyway, so fail fast with a clear message.
        if (trim((string) config('app.key', '')) === '') {
            throw new \InvalidArgumentException(
                'auth_system: APP_KEY is not set. It is the pepper for OTP / backup-code hashing and '
                . 'the key for 2FA secret encryption. Run `php artisan key:generate`.',
            );
        }

        // v2.6 — warn at boot if the log phone driver is wired up anywhere
        // other than local/testing. It writes plain OTP codes to the Laravel
        // log; the driver itself hard-fails at send time outside local/testing
        // (see LogPhoneDriver), but this surfaces the misconfiguration early.
        if (! app()->environment('local', 'testing') && (bool) config('auth_system.phone.enabled', false)) {
            foreach (['sms', 'voice', 'whatsapp'] as $channel) {
                $provider = (string) config("auth_system.phone.channels.{$channel}.provider", '');
                if ($provider === 'log') {
                    \Illuminate\Support\Facades\Log::warning(
                        "[laravel-auth] Phone channel '{$channel}' is configured to use the 'log' driver "
                        . 'outside local/testing. The log driver writes plaintext OTP codes to the '
                        . 'application log and will REFUSE to send in this environment. '
                        . 'Switch to a real provider (infobip, twilio, messagecentral, …).',
                    );
                }
            }
        }
    }

    /**
     * Apply the opt-in security PROFILE (security.profile = relaxed|balanced|
     * high). The profile fills curated secure defaults — but ONLY for keys
     * whose associated env var is unset, so any explicit `.env` value the
     * developer set always wins. "developer freedom" preserved.
     *
     * Caveat (documented in UPGRADING): if the host published the package
     * config and hardcoded a value WITHOUT going through env, the profile
     * cannot see that override and may set its own value. Use env for
     * profile-controlled keys (or set security.profile=null and configure
     * every flag explicitly).
     */
    private function applySecurityProfile(): void
    {
        $profile = (string) config('auth_system.security.profile', '');
        $map     = $this->securityProfileMap($profile);

        if ($map === []) {
            return;
        }

        foreach ($map as $configKey => $spec) {
            if (env($spec['env']) === null) {
                config(["auth_system.{$configKey}" => $spec['value']]);
            }
        }
    }

    /**
     * Mapping of preset → list of [config-key => [env, value]]. 'relaxed' is
     * effectively the v2.7 defaults (no overrides). 'balanced' enables the
     * obvious anti-DoS / anti-CSRF flags. 'high' enables everything the
     * library exposes as a hardening flag.
     *
     * @return array<string, array{env: string, value: mixed}>
     */
    private function securityProfileMap(string $profile): array
    {
        return match ($profile) {
            'high' => [
                'api_tokens.strict_abilities'                          => ['env' => 'AUTH_API_TOKENS_STRICT',                 'value' => true],
                'api_tokens.require_step_up'                           => ['env' => 'AUTH_API_TOKENS_REQUIRE_STEP_UP',        'value' => true],
                'api_tokens.admin_require_step_up'                     => ['env' => 'AUTH_API_TOKENS_ADMIN_REQUIRE_STEP_UP',  'value' => true],
                'social.enforce_state'                                 => ['env' => 'AUTH_SOCIAL_ENFORCE_STATE',              'value' => true],
                'security.lockout.scope'                               => ['env' => 'AUTH_LOCKOUT_SCOPE',                     'value' => 'email_and_ip'],
                'password_reset.auto_login'                            => ['env' => 'AUTH_PASSWORD_RESET_AUTO_LOGIN',         'value' => false],
                'account.status.admin_actions.enforce_role_hierarchy'  => ['env' => 'AUTH_ACCOUNT_STATUS_HIERARCHY',          'value' => true],
                'account.status.require_step_up'                       => ['env' => 'AUTH_ACCOUNT_STATUS_REQUIRE_STEP_UP',    'value' => true],
                'trusted_devices.registration_device_level'            => ['env' => 'AUTH_TRUST_REG_DEVICE_LEVEL',            'value' => 'medium'],
                'two_factor.required'                                  => ['env' => 'AUTH_2FA_REQUIRED',                      'value' => true],
            ],
            'balanced' => [
                'api_tokens.strict_abilities' => ['env' => 'AUTH_API_TOKENS_STRICT',    'value' => true],
                'social.enforce_state'        => ['env' => 'AUTH_SOCIAL_ENFORCE_STATE', 'value' => true],
                'security.lockout.scope'      => ['env' => 'AUTH_LOCKOUT_SCOPE',        'value' => 'email_and_ip'],
            ],
            'relaxed' => [
                // 'relaxed' = the v2.7 defaults; nothing to override.
            ],
            default => [], // unknown profile name → no-op (developer's config wins)
        };
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
        $router->aliasMiddleware('auth.2fa', Require2FA::class);
        $router->aliasMiddleware('auth.step-up', RequireStepUp::class);
        $router->aliasMiddleware('auth.require-2fa-enrolled', EnforceRequired2FA::class);
        $router->aliasMiddleware('auth.api-token-stepup', RequireStepUpForApiTokenCreation::class);
        $router->aliasMiddleware('auth.admin-gate', AdminGate::class);

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
