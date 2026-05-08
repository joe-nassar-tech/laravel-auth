<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Joe404\LaravelAuth\Contracts\ResponseFormatterContract;
use Symfony\Component\HttpFoundation\Response;

class AuthMode
{
    public function handle(Request $request, Closure $next, string ...$allowedModes): mixed
    {
        $mode = (string) config('auth_system.mode', 'both');

        if (! empty($allowedModes) && ! in_array($mode, $allowedModes, true)) {
            return response()->json(
                app(ResponseFormatterContract::class)->format(
                    false,
                    'This endpoint is not available in the current authentication mode.',
                    [],
                    [],
                ),
                Response::HTTP_FORBIDDEN,
            );
        }

        return $next($request);
    }
}
