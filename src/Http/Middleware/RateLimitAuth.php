<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Joe404\LaravelAuth\Contracts\ResponseFormatterContract;
use Joe404\LaravelAuth\Services\RateLimitService;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

class RateLimitAuth
{
    public function __construct(
        private readonly RateLimitService $rateLimitService,
    ) {}

    public function handle(Request $request, Closure $next, string $configKey): mixed
    {
        $ip    = (string) $request->ip();
        $email = (string) $request->input('email', '');

        try {
            $this->rateLimitService->check($configKey, $ip);

            if ($email !== '') {
                $this->rateLimitService->check($configKey, $email);
            }
        } catch (TooManyRequestsHttpException $e) {
            $retryAfter = $e->getHeaders()['Retry-After'] ?? $e->getStatusCode();

            return response()->json(
                app(ResponseFormatterContract::class)->format(false, $e->getMessage(), [], []),
                Response::HTTP_TOO_MANY_REQUESTS,
                ['Retry-After' => $retryAfter],
            );
        }

        /** @var Response $response */
        $response = $next($request);

        if ($response->isSuccessful()) {
            $this->rateLimitService->clear($configKey, $ip);

            if ($email !== '') {
                $this->rateLimitService->clear($configKey, $email);
            }
        }

        return $response;
    }
}
