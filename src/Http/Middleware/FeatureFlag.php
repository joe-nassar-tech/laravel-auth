<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Joe404\LaravelAuth\Contracts\ResponseFormatterContract;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates a route group on a feature flag in `config/auth_system.php`.
 *
 * The flag is checked at request time, NOT at route-cache build time, so
 * `php artisan route:cache` followed by toggling the flag does not silently
 * leave routes registered/missing. When disabled, returns a 404 envelope.
 */
class FeatureFlag
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        if (! (bool) config("auth_system.{$feature}.enabled", false)) {
            $formatter = app(ResponseFormatterContract::class);

            return response()->json(
                $formatter->format(false, 'Not Found.', [], []),
                Response::HTTP_NOT_FOUND,
            );
        }

        return $next($request);
    }
}
