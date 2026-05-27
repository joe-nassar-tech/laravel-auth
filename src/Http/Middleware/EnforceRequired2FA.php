<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Http\Middleware;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Joe404\LaravelAuth\Contracts\ResponseFormatterContract;
use Joe404\LaravelAuth\Services\TwoFactorService;

/**
 * Enforces auth_system.two_factor.required on the package's OWN authenticated
 * routes. When 2FA is required but the user has not enrolled any verified
 * method, every package endpoint is blocked with a `must_enroll_2fa` envelope
 * — EXCEPT the endpoints the user needs to actually enroll or end the session
 * (the 2fa/* management routes, logout, password/confirm, me, session/clear),
 * so a required-2FA policy can never lock a user out of enrolling.
 *
 * No-ops entirely when two_factor.required is false (the default), so existing
 * apps are unaffected. Deliberately applied only to package route groups; host
 * apps opt their own routes in by adding the `auth.require-2fa-enrolled` alias.
 */
class EnforceRequired2FA
{
    public function __construct(
        private readonly Container $container,
        private readonly TwoFactorService $twoFactor,
    ) {}

    public function handle(Request $request, Closure $next): mixed
    {
        if (! (bool) config('auth_system.two_factor.enabled', true)
            || ! (bool) config('auth_system.two_factor.required', false)
        ) {
            return $next($request);
        }

        $user = $request->user();

        // Unauthenticated requests are the auth guard's problem, not ours.
        if ($user === null) {
            return $next($request);
        }

        // Already enrolled — the login flow's 2FA challenge gated this session.
        if ($this->twoFactor->hasAnyVerifiedMethod($user)) {
            return $next($request);
        }

        // Let through the endpoints required to enroll or to log out.
        if ($this->isExempt($request)) {
            return $next($request);
        }

        return new JsonResponse(
            $this->container->make(ResponseFormatterContract::class)->format(
                false,
                '2FA enrollment is required before you can continue.',
                ['step_up' => 'enroll_2fa', 'must_enroll_2fa' => true],
                [],
            ),
            403,
        );
    }

    private function isExempt(Request $request): bool
    {
        $path = trim($request->path(), '/');

        // All 2FA management endpoints live under "<prefix>/2fa/...".
        if (str_contains($path, '/2fa/') || str_ends_with($path, '/2fa')) {
            return true;
        }

        foreach (['me', 'logout', 'logout/all', 'password/confirm', 'session/clear'] as $tail) {
            if ($path === $tail || str_ends_with($path, '/' . $tail)) {
                return true;
            }
        }

        return false;
    }
}
