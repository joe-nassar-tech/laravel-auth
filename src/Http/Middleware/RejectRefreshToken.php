<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Joe404\LaravelAuth\Contracts\ResponseFormatterContract;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class RejectRefreshToken
{
    public function handle(Request $request, Closure $next): mixed
    {
        $token = $request->user()?->currentAccessToken();

        // Refresh tokens are stored in auth_refresh_tokens, not personal_access_tokens.
        // This check is defense-in-depth against any legacy refresh token that somehow
        // ended up as a Sanctum token (name 'auth-refresh') from an older installation.
        if ($token instanceof PersonalAccessToken && str_starts_with((string) $token->name, 'auth-refresh')) {
            return response()->json(
                app(ResponseFormatterContract::class)->format(
                    false,
                    'Unauthenticated.',
                    [],
                    [],
                ),
                Response::HTTP_UNAUTHORIZED,
            );
        }

        return $next($request);
    }
}
