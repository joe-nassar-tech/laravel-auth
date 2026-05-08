<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Services;

use Illuminate\Foundation\Auth\User;
use Laravel\Sanctum\PersonalAccessToken;

class TokenService
{
    public function issue(User $user, string $name = 'auth-token', array $abilities = ['*']): array
    {
        $expiresAt = null;
        $minutes   = (int) config('auth_system.token.expiration_minutes', 10080);

        if ($minutes > 0) {
            $expiresAt = now()->addMinutes($minutes);
        }

        $newToken = $user->createToken($name, $abilities, $expiresAt);

        return [
            'plain_text_token' => $newToken->plainTextToken,
            'token'            => $newToken->accessToken,
        ];
    }

    public function revoke(int $tokenId): void
    {
        PersonalAccessToken::findOrFail($tokenId)->delete();
    }

    public function revokeAll(User $user, ?int $exceptTokenId = null): void
    {
        $query = $user->tokens();

        if ($exceptTokenId !== null) {
            $query->where('id', '!=', $exceptTokenId);
        }

        $query->delete();
    }

    public function rotate(User $user, PersonalAccessToken $current): array
    {
        $name      = $current->name;
        $abilities = $current->abilities;

        $current->delete();

        return $this->issue($user, $name, $abilities);
    }
}
