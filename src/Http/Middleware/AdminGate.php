<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Http\Middleware;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Joe404\LaravelAuth\Contracts\ResponseFormatterContract;

/**
 * Configurable gate for the package admin sections. Reads two config keys
 * under `auth_system.<section>` at REQUEST time (so per-test config overrides
 * and `php artisan route:cache` both work):
 *
 *   - `admin_middleware` (string|null): pipe-separated tokens treated as ROLE
 *     OR PERMISSION names (Spatie). When set, this wins. Example:
 *       'super-admin|users.manage-status'
 *   - `admin_ability` (string): legacy pipe-separated ROLE names. Fallback
 *     when `admin_middleware` is null (preserves v2.7 behavior).
 *
 * The check passes if the authenticated user has ANY of the listed roles or
 * permissions. A missing Spatie permission entry is silently treated as
 * "doesn't match" (so a pure-role spec still works on apps that don't define
 * permissions). Returns the package's standard 401/403 JSON envelope on fail.
 *
 * Usage in routes:
 *   ->middleware('auth.admin-gate:account.status')
 *   ->middleware('auth.admin-gate:api_tokens')
 */
class AdminGate
{
    public function __construct(private readonly Container $container) {}

    public function handle(Request $request, Closure $next, string $section): mixed
    {
        $user = $request->user();

        if ($user === null) {
            return $this->json(false, 'Unauthenticated.', 401);
        }

        $override = config("auth_system.{$section}.admin_middleware");
        $fallback = (string) config("auth_system.{$section}.admin_ability", 'super-admin|admin');
        $spec     = is_string($override) && $override !== '' ? $override : $fallback;

        $tokens = array_values(array_filter(
            array_map('trim', explode('|', $spec)),
            static fn (string $t): bool => $t !== '',
        ));

        foreach ($tokens as $token) {
            if (method_exists($user, 'hasRole')) {
                try {
                    if ($user->hasRole($token)) {
                        return $next($request);
                    }
                } catch (\Throwable) {
                    // Unknown role name → not a match; fall through.
                }
            }

            if (method_exists($user, 'hasPermissionTo')) {
                try {
                    if ($user->hasPermissionTo($token)) {
                        return $next($request);
                    }
                } catch (\Throwable) {
                    // Unknown permission name → not a match; fall through.
                }
            }
        }

        return $this->json(false, 'Forbidden.', 403);
    }

    private function json(bool $success, string $message, int $status): JsonResponse
    {
        /** @var ResponseFormatterContract $formatter */
        $formatter = $this->container->make(ResponseFormatterContract::class);

        return new JsonResponse($formatter->format($success, $message, [], []), $status);
    }
}
