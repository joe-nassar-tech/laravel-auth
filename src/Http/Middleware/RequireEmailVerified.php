<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Joe404\LaravelAuth\Contracts\ResponseFormatterContract;
use Symfony\Component\HttpFoundation\Response;

class RequireEmailVerified
{
    public function handle(Request $request, Closure $next): mixed
    {
        $user = $request->user();

        if ($user !== null && method_exists($user, 'hasVerifiedEmail') && ! $user->hasVerifiedEmail()) {
            return response()->json(
                app(ResponseFormatterContract::class)->format(
                    false,
                    'Email address is not verified.',
                    [],
                    [],
                ),
                Response::HTTP_FORBIDDEN,
            );
        }

        return $next($request);
    }
}
