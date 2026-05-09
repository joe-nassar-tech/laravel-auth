<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Services;

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Joe404\LaravelAuth\Exceptions\AuthException;
use Joe404\LaravelAuth\Exceptions\TokenExpiredException;
use Joe404\LaravelAuth\Models\AuthRefreshToken;
use Laravel\Sanctum\PersonalAccessToken;

class TokenService
{
    /**
     * Issue a Sanctum access token and a paired refresh token.
     *
     * The refresh token is stored as a hashed row in auth_refresh_tokens — it is
     * NOT a Sanctum personal access token, so it can never authenticate a request
     * through the auth:sanctum guard or any host-app route.
     *
     * Access tokens keep ['*'] abilities for host-app Sanctum compatibility.
     *
     * @return array{plain_text_token: string, plain_refresh_token: string, token: PersonalAccessToken}
     */
    public function issue(User $user, string $clientType = 'mobile'): array
    {
        return $this->issueInFamily($user, $clientType, Str::uuid()->toString(), null);
    }

    /**
     * @return array{plain_text_token: string, plain_refresh_token: string, token: PersonalAccessToken}
     */
    private function issueInFamily(User $user, string $clientType, string $familyId, ?int $parentId): array
    {
        $accessMinutes  = (int) config("auth_system.token_ttl.{$clientType}.access_minutes", 10080);
        $refreshMinutes = (int) config("auth_system.token_ttl.{$clientType}.refresh_minutes", 43200);

        $accessExpiresAt  = $accessMinutes > 0 ? now()->addMinutes($accessMinutes) : null;
        $refreshExpiresAt = $refreshMinutes > 0 ? now()->addMinutes($refreshMinutes) : null;

        $access = $user->createToken('auth-access', ['*'], $accessExpiresAt);

        $rawRefresh = Str::random(64);
        AuthRefreshToken::create([
            'user_id'         => $user->getKey(),
            'access_token_id' => $access->accessToken->id,
            'token_hash'      => hash('sha256', $rawRefresh),
            'family_id'       => $familyId,
            'parent_id'       => $parentId,
            'expires_at'      => $refreshExpiresAt,
        ]);

        return [
            'plain_text_token'    => $access->plainTextToken,
            'token'               => $access->accessToken,
            'plain_refresh_token' => $rawRefresh,
        ];
    }

    /**
     * Atomically rotate a refresh token.
     *
     * Uses a transaction + SELECT FOR UPDATE to prevent two concurrent requests
     * from consuming the same refresh token and minting multiple new pairs.
     *
     * If a consumed/revoked refresh token is presented, the entire token family
     * is revoked — this is the standard refresh-token-rotation reuse-detection
     * pattern. It contains the blast radius if a refresh token is leaked: as
     * soon as either the legitimate client or the attacker performs a second
     * refresh, the family is killed and both must re-authenticate.
     *
     * @return array{plain_text_token: string, plain_refresh_token: string, token: PersonalAccessToken, user: User}
     */
    public function refresh(string $rawRefreshToken, string $clientType = 'mobile'): array
    {
        // First, look up the token state OUTSIDE the rotation transaction so
        // that reuse-detection side effects (revoking the whole family) are
        // committed even when we throw afterwards.
        /** @var AuthRefreshToken|null $record */
        $record = AuthRefreshToken::where('token_hash', hash('sha256', $rawRefreshToken))->first();

        if ($record === null) {
            throw new AuthException('Invalid refresh token.');
        }

        if ($record->isRevoked()) {
            throw new AuthException('Refresh token has been revoked. Please log in again.');
        }

        if ($record->isConsumed()) {
            // Reuse detected — revoke the whole family so an attacker who
            // replayed an already-rotated token cannot continue using the
            // most recent descendant either.
            $this->revokeFamily((string) $record->family_id, 'reuse_detected');

            throw new AuthException('Refresh token reuse detected. All sessions for this family have been revoked.');
        }

        if ($record->isExpired()) {
            $record->update(['revoked_at' => now(), 'revoked_reason' => 'expired']);
            throw new TokenExpiredException('Refresh token has expired. Please log in again.');
        }

        // Now perform the rotation atomically. Re-select with FOR UPDATE so
        // two concurrent refreshes cannot both pass the consumed check.
        return DB::transaction(function () use ($record, $clientType): array {
            /** @var AuthRefreshToken $locked */
            $locked = AuthRefreshToken::where('id', $record->getKey())->lockForUpdate()->first();

            if ($locked === null || $locked->isConsumed() || $locked->isRevoked()) {
                throw new AuthException('Invalid refresh token.');
            }

            $locked->update(['consumed_at' => now()]);

            // Revoke only the paired access token; other sessions stay intact.
            if ($locked->access_token_id !== null) {
                PersonalAccessToken::find($locked->access_token_id)?->delete();
            }

            /** @var class-string<User> $userModel */
            $userModel = config('auth.providers.users.model', \App\Models\User::class);

            /** @var User|null $user */
            $user = $userModel::find($locked->user_id);

            if ($user === null) {
                throw new AuthException('Invalid refresh token.');
            }

            $tokenData         = $this->issueInFamily($user, $clientType, (string) $locked->family_id, (int) $locked->getKey());
            $tokenData['user'] = $user;

            return $tokenData;
        });
    }

    public function revokeFamily(string $familyId, string $reason): void
    {
        $tokenIds = AuthRefreshToken::where('family_id', $familyId)
            ->whereNull('revoked_at')
            ->pluck('access_token_id')
            ->filter()
            ->values()
            ->all();

        AuthRefreshToken::where('family_id', $familyId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'revoked_reason' => $reason]);

        if ($tokenIds !== []) {
            PersonalAccessToken::whereIn('id', $tokenIds)->delete();
        }
    }

    public function revoke(int $tokenId): void
    {
        // Revoke paired refresh token before deleting the access token.
        AuthRefreshToken::where('access_token_id', $tokenId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'revoked_reason' => 'logout']);

        PersonalAccessToken::find($tokenId)?->delete();
    }

    public function revokeAll(User $user, ?int $exceptTokenId = null): void
    {
        // Revoke all refresh tokens for this user (covers unlinked ones too).
        $refreshQuery = AuthRefreshToken::where('user_id', $user->getKey())
            ->whereNull('revoked_at');

        if ($exceptTokenId !== null) {
            $refreshQuery->where(function ($q) use ($exceptTokenId): void {
                $q->where('access_token_id', '!=', $exceptTokenId)
                  ->orWhereNull('access_token_id');
            });
        }

        $refreshQuery->update(['revoked_at' => now(), 'revoked_reason' => 'revoke_all']);

        // Revoke Sanctum access tokens.
        $accessQuery = $user->tokens();
        if ($exceptTokenId !== null) {
            $accessQuery->where('id', '!=', $exceptTokenId);
        }
        $accessQuery->delete();
    }
}
