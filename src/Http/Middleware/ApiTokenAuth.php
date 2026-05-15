<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Joe404\LaravelAuth\Contracts\ResponseFormatterContract;
use Joe404\LaravelAuth\Services\ApiTokenService;

/**
 * Usage:
 *   ->middleware('auth.api-token')              // any valid token
 *   ->middleware('auth.api-token:read:orders')  // must have 'read:orders' ability
 */
class ApiTokenAuth
{
    public function __construct(private readonly ApiTokenService $service) {}

    public function handle(Request $request, Closure $next, string ...$abilities): mixed
    {
        $bearer = $request->bearerToken();

        if ($bearer === null || !str_starts_with($bearer, 'auth_at_')) {
            return response()->json(
                app(ResponseFormatterContract::class)->format(false, 'API token required.', [], []),
                401,
            );
        }

        try {
            $token = $this->service->validate($bearer);
        } catch (\Throwable $e) {
            return response()->json(
                app(ResponseFormatterContract::class)->format(false, $e->getMessage(), [], []),
                401,
            );
        }

        foreach ($abilities as $ability) {
            if (!$this->service->can($token, $ability)) {
                return response()->json(
                    app(ResponseFormatterContract::class)->format(
                        false,
                        "Missing required ability: [{$ability}].",
                        [],
                        [],
                    ),
                    403,
                );
            }
        }

        $request->merge(['_api_token' => $token]);

        // Set the authenticated user from the token owner so $request->user()
        // works in downstream controllers and middleware.
        if ($token->owner_id !== null && $token->owner_type !== null) {
            $ownerClass = $token->owner_type;
            if (class_exists($ownerClass)) {
                $owner = $ownerClass::find($token->owner_id);
                if ($owner !== null) {
                    Auth::setUser($owner);
                }
            }
        }

        return $next($request);
    }
}
