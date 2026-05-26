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
use Laravel\Sanctum\PersonalAccessToken;

class PasswordConfirmController extends Controller
{
    use ResolvesMessages, RespondsWithJson;

    public function confirm(Request $request): JsonResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string'],
        ]);

        $user = $request->user();

        if (! Hash::check((string) $data['password'], (string) $user->password)) {
            return $this->failure('Current password is incorrect.', [], 422);
        }

        $ttl = max(1, (int) config('auth_system.two_factor.sudo_ttl_minutes', 15));

        $token = $request->user()?->currentAccessToken();
        $id    = $token instanceof PersonalAccessToken ? $token->id : $request->session()?->getId();

        Cache::put("auth:sudo:{$user->getKey()}:" . (string) $id, true, now()->addMinutes($ttl));

        return $this->success('Password confirmed.', [
            'expires_in_minutes' => $ttl,
        ]);
    }
}
