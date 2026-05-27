<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Http\Middleware;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Joe404\LaravelAuth\Contracts\ResponseFormatterContract;
use Joe404\LaravelAuth\Services\TwoFactorChallengeService;
use Joe404\LaravelAuth\Services\TwoFactorService;

/**
 * Step-up middleware for sensitive endpoints. Requires:
 *
 *   - The user has completed a 2FA challenge recently (within sudo_ttl), OR
 *   - The user has no 2FA enrolled AND the configured fallback behavior
 *     produces a usable token (password_confirm | force_enroll | block).
 *
 * Apply via the "auth.2fa" alias.
 */
class Require2FA
{
    public function __construct(
        private readonly Container $container,
        private readonly TwoFactorService $twoFactor,
        private readonly TwoFactorChallengeService $challenge,
    ) {}

    public function handle(Request $request, Closure $next): mixed
    {
        $user = $request->user();

        if ($user === null) {
            return $this->json(false, 'Unauthenticated.', 401, ['errors' => []]);
        }

        // Fast path: if the user has a fresh 2FA verification stamped on the
        // session-or-token within sudo TTL, allow.
        if ($this->hasRecent2faStamp($user, $request)) {
            return $next($request);
        }

        $hasMethods = $this->twoFactor->hasAnyVerifiedMethod($user);
        $behavior   = (string) config('auth_system.two_factor.middleware_behavior', 'password_confirm');

        // Has 2FA enrolled → require a step-up challenge.
        if ($hasMethods) {
            $challenge = $this->challenge->createForUser($user, null, $request);

            return $this->json(false, '2FA verification required.', 403, [
                'data' => [
                    'step_up'           => 'two_factor',
                    'challenge_token'   => $challenge['challenge_token'],
                    'method'            => $challenge['method'],
                    'available_methods' => $challenge['available_methods'],
                    'expires_in'        => $challenge['expires_in'],
                ],
            ]);
        }

        // No 2FA enrolled — apply fallback behavior.
        return match ($behavior) {
            'force_enroll'     => $this->json(false, '2FA enrollment required.', 403, [
                'data' => ['step_up' => 'enroll_2fa'],
            ]),
            'password_confirm' => $this->handlePasswordConfirm($request, $next),
            default            => $this->json(false, '2FA required but not enrolled.', 403, [
                'data' => ['step_up' => 'enroll_2fa'],
            ]),
        };
    }

    private function handlePasswordConfirm(Request $request, Closure $next): mixed
    {
        $user      = $request->user();
        $cacheKey  = $this->sudoCacheKey($user->getKey(), $request);
        $confirmed = Cache::get($cacheKey);

        if ($confirmed === true) {
            return $next($request);
        }

        return $this->json(false, 'Password confirmation required.', 403, [
            'data' => ['step_up' => 'password_confirm'],
        ]);
    }

    private function hasRecent2faStamp($user, Request $request): bool
    {
        $key  = $this->twoFactorStampKey($user->getKey(), $request);
        $stamp = Cache::get($key);

        return $stamp !== null;
    }

    private function twoFactorStampKey(int|string $userId, Request $request): string
    {
        $token = $request->user()?->currentAccessToken();
        $id    = $token instanceof \Laravel\Sanctum\PersonalAccessToken ? $token->id : $request->session()?->getId();

        return "auth:2fa:stamp:{$userId}:" . (string) $id;
    }

    private function sudoCacheKey(int|string $userId, Request $request): string
    {
        $token = $request->user()?->currentAccessToken();
        $id    = $token instanceof \Laravel\Sanctum\PersonalAccessToken ? $token->id : $request->session()?->getId();

        return "auth:sudo:{$userId}:" . (string) $id;
    }

    /**
     * @param array{data?:array<string,mixed>,errors?:array<string,mixed>} $extra
     */
    private function json(bool $success, string $message, int $status, array $extra = []): JsonResponse
    {
        /** @var ResponseFormatterContract $formatter */
        $formatter = $this->container->make(ResponseFormatterContract::class);

        return new JsonResponse(
            $formatter->format(
                $success,
                $message,
                (array) ($extra['data'] ?? []),
                (array) ($extra['errors'] ?? []),
            ),
            $status,
        );
    }
}
