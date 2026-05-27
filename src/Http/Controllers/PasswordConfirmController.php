<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Joe404\LaravelAuth\Http\Concerns\ResolvesMessages;
use Joe404\LaravelAuth\Http\Concerns\RespondsWithJson;
use Joe404\LaravelAuth\Services\RateLimitService;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

class PasswordConfirmController extends Controller
{
    use ResolvesMessages, RespondsWithJson;

    public function confirm(Request $request, RateLimitService $rateLimit): JsonResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string'],
        ]);

        $user = $request->user();

        // Per-user throttle: a hijacked session must not be able to brute-force
        // the account password here to mint a sudo window. Keyed on the user
        // (not the IP) so rotating source IPs does not defeat it.
        $subject = 'user:' . $user->getKey();

        try {
            $rateLimit->check('password_confirm', $subject);
        } catch (TooManyRequestsHttpException) {
            return $this->failure('Too many attempts. Please try again later.', [], 429);
        }

        if (! Hash::check((string) $data['password'], (string) $user->password)) {
            return $this->failure('Current password is incorrect.', [], 422);
        }

        // Correct password — reset the throttle so a legitimate confirm does
        // not count against the user.
        $rateLimit->clear('password_confirm', $subject);

        $ttl = max(1, (int) config('auth_system.two_factor.sudo_ttl_minutes', 15));

        $token = $request->user()?->currentAccessToken();
        $id    = $token instanceof PersonalAccessToken ? $token->id : $request->session()?->getId();

        Cache::put("auth:sudo:{$user->getKey()}:" . (string) $id, true, now()->addMinutes($ttl));

        return $this->success('Password confirmed.', [
            'expires_in_minutes' => $ttl,
        ]);
    }
}
