<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Joe404\LaravelAuth\Exceptions\TokenExpiredException;
use Joe404\LaravelAuth\Exceptions\TokenRevokedException;
use Joe404\LaravelAuth\Models\AuthApiToken;

class ApiTokenService
{
    public function issue(
        string $name,
        array  $abilities = ['read'],
        ?int   $expiresInDays = null,
        mixed  $owner = null,
    ): array {
        $raw    = Str::random(64);
        $hash   = $this->hash($raw);
        $bearer = 'auth_at_' . base64_encode($raw);

        $token = AuthApiToken::create([
            'name'       => $name,
            'token_hash' => $hash,
            'abilities'  => $abilities ?: config('auth_system.api_token.abilities_default', ['read']),
            'owner_type' => $owner !== null ? get_class($owner) : null,
            'owner_id'   => $owner !== null && method_exists($owner, 'getKey') ? $owner->getKey() : null,
            'expires_at' => $expiresInDays !== null ? now()->addDays($expiresInDays) : null,
        ]);

        return ['raw_token' => $bearer, 'token' => $token];
    }

    public function validate(string $bearerToken): AuthApiToken
    {
        if (!str_starts_with($bearerToken, 'auth_at_')) {
            throw new TokenRevokedException('Invalid API token format.');
        }

        try {
            $raw = $this->parseRawToken($bearerToken);
        } catch (\Throwable) {
            throw new TokenRevokedException('Invalid API token format.');
        }

        $hash  = $this->hash($raw);
        $token = AuthApiToken::where('token_hash', $hash)->first();

        if ($token === null || !$token->is_active) {
            throw new TokenRevokedException();
        }

        if ($token->isExpired()) {
            throw new TokenExpiredException();
        }

        $token->update(['last_used_at' => now()]);

        return $token->fresh();
    }

    public function can(AuthApiToken $token, string $ability): bool
    {
        $abilities = $token->abilities;

        return in_array('*', $abilities, true) || in_array($ability, $abilities, true);
    }

    public function revoke(int $tokenId): void
    {
        AuthApiToken::where('id', $tokenId)->update(['is_active' => false]);
    }

    public function list(?string $ownerType = null, ?int $ownerId = null): LengthAwarePaginator
    {
        $query = AuthApiToken::latest();

        if ($ownerType !== null) {
            $query->where('owner_type', $ownerType)->where('owner_id', $ownerId);
        }

        return $query->paginate(15);
    }

    public function deleteExpired(): int
    {
        return AuthApiToken::whereNotNull('expires_at')->where('expires_at', '<', now())->delete();
    }

    private function parseRawToken(string $bearerToken): string
    {
        $encoded = substr($bearerToken, strlen('auth_at_'));
        $decoded = base64_decode($encoded, strict: true);

        if ($decoded === false) {
            throw new TokenRevokedException('Invalid API token encoding.');
        }

        return $decoded;
    }

    private function hash(string $rawToken): string
    {
        return hash('sha256', $rawToken);
    }
}
