<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Gates API-token creation behind a fresh step-up (sudo password / 2FA per
 * two_factor.step_up_mode) — but ONLY when api_tokens.require_step_up is
 * enabled. The flag is read at REQUEST time (not at route-registration time)
 * so `php artisan route:cache` and per-environment config both behave, and
 * toggling the flag never requires re-caching routes.
 *
 * Delegates to RequireStepUp for the actual sudo / challenge logic so there is
 * a single source of truth for how a step-up is satisfied.
 */
class RequireStepUpForApiTokenCreation
{
    public function __construct(private readonly RequireStepUp $stepUp) {}

    public function handle(Request $request, Closure $next): mixed
    {
        if (! (bool) config('auth_system.api_tokens.require_step_up', false)) {
            return $next($request);
        }

        return $this->stepUp->handle($request, $next);
    }
}
