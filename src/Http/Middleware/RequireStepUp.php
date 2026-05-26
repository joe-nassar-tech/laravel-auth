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
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Config-driven step-up gate for sensitive actions (remove a 2FA method,
 * regenerate backup codes, change phone, admin status change). Apply via the
 * "auth.step-up" alias.
 *
 * Behavior is controlled by `auth_system.two_factor.step_up_mode`:
 *   - "password_confirm" (default): the user must have a fresh sudo window
 *     (POST /auth/password/confirm). Works for users with or without 2FA.
 *   - "two_factor": the user must pass a fresh 2FA challenge; if they have no
 *     2FA method enrolled, it falls back to password_confirm.
 *
 * A recent login/step-up 2FA stamp satisfies the gate in either mode, so a
 * user who just completed 2FA isn't asked again within the sudo TTL.
 */
class RequireStepUp
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
            return $this->json(false, 'Unauthenticated.', 401);
        }

        // A recent 2FA stamp (from login or a prior step-up) always satisfies
        // the gate within the sudo TTL.
        if ($this->cacheFlag($this->stampKey($user->getKey(), $request))) {
            return $next($request);
        }

        $mode = (string) config('auth_system.two_factor.step_up_mode', 'password_confirm');

        if ($mode === 'two_factor' && $this->twoFactor->hasAnyVerifiedMethod($user)) {
            $payload = $this->challenge->createForUser($user, null, $request);

            return $this->json(false, '2FA verification required.', 403, [
                'step_up'           => 'two_factor',
                'challenge_token'   => $payload['challenge_token'],
                'method'            => $payload['method'],
                'available_methods' => $payload['available_methods'],
                'expires_in'        => $payload['expires_in'],
            ]);
        }

        // password_confirm mode (or two_factor with no enrolled method).
        if ($this->cacheFlag($this->sudoKey($user->getKey(), $request))) {
            return $next($request);
        }

        return $this->json(false, 'Password confirmation required.', 403, [
            'step_up' => 'password_confirm',
        ]);
    }

    private function cacheFlag(string $key): bool
    {
        return Cache::get($key) !== null;
    }

    private function stampKey(int|string $userId, Request $request): string
    {
        return "auth:2fa:stamp:{$userId}:" . $this->sessionOrTokenId($request);
    }

    private function sudoKey(int|string $userId, Request $request): string
    {
        return "auth:sudo:{$userId}:" . $this->sessionOrTokenId($request);
    }

    private function sessionOrTokenId(Request $request): string
    {
        $token = $request->user()?->currentAccessToken();
        $id    = $token instanceof PersonalAccessToken ? $token->id : $request->session()?->getId();

        return (string) $id;
    }

    /** @param array<string,mixed> $data */
    private function json(bool $success, string $message, int $status, array $data = []): JsonResponse
    {
        /** @var ResponseFormatterContract $formatter */
        $formatter = $this->container->make(ResponseFormatterContract::class);

        return new JsonResponse($formatter->format($success, $message, $data, []), $status);
    }
}
