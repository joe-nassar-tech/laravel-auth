<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Services;

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Joe404\LaravelAuth\Exceptions\AccountInactiveException;
use Joe404\LaravelAuth\Exceptions\AuthException;
use Joe404\LaravelAuth\Exceptions\EmailNotVerifiedException;
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
        $hash = hash('sha256', $rawRefreshToken);

        // The locked transaction either rotates successfully or returns a
        // typed outcome describing what to do next. We never throw from
        // inside the transaction — throwing would roll back side effects
        // like the reuse-detection family revoke, which MUST commit.
        $outcome = DB::transaction(function () use ($hash, $clientType): array {
            /** @var AuthRefreshToken|null $locked */
            $locked = AuthRefreshToken::where('token_hash', $hash)->lockForUpdate()->first();

            if ($locked === null) {
                return ['status' => 'invalid'];
            }

            if ($locked->isRevoked()) {
                return ['status' => 'revoked'];
            }

            if ($locked->isConsumed()) {
                // Strict RFC 6749 §10.4 rotation: any presentation of an
                // already-consumed refresh token nukes the family. Covers
                // both attacker replay and concurrent legit retry that
                // lost the lock race.
                return ['status' => 'reused', 'family_id' => (string) $locked->family_id];
            }

            if ($locked->isExpired()) {
                $locked->update(['revoked_at' => now(), 'revoked_reason' => 'expired']);
                return ['status' => 'expired'];
            }

            /** @var class-string<User> $userModel */
            $userModel = config('auth.providers.users.model', \App\Models\User::class);

            /** @var User|null $user */
            $user = $userModel::find($locked->user_id);

            if ($user === null) {
                return ['status' => 'invalid'];
            }

            // Re-validate the account is still allowed to authenticate.
            // Without this, a suspended/disabled/soft-deleted user keeps
            // minting fresh access tokens for the lifetime of their
            // refresh token. Status exceptions propagate; the rotation
            // never happened so rollback is the correct outcome.
            $this->assertUserCanRefresh($user);

            $locked->update(['consumed_at' => now()]);

            // Revoke only the paired access token; other sessions stay intact.
            if ($locked->access_token_id !== null) {
                PersonalAccessToken::find($locked->access_token_id)?->delete();
            }

            $tokenData                      = $this->issueInFamily($user, $clientType, (string) $locked->family_id, (int) $locked->getKey());
            $tokenData['user']              = $user;
            $tokenData['previous_token_id'] = $locked->access_token_id;

            return ['status' => 'ok', 'data' => $tokenData];
        });

        return match ($outcome['status']) {
            'ok'      => $outcome['data'],
            'invalid' => throw new AuthException('Invalid refresh token.', 'refresh_token_invalid'),
            'revoked' => throw new AuthException('Refresh token has been revoked. Please log in again.', 'refresh_token_revoked'),
            'expired' => throw new TokenExpiredException('Refresh token has expired. Please log in again.', 'refresh_token_expired'),
            'reused'  => $this->handleReuse((string) $outcome['family_id']),
        };
    }

    /**
     * @return never
     */
    private function handleReuse(string $familyId): array
    {
        // Run AFTER the rotation transaction commits so the revoke writes
        // can never be rolled back by the throw that follows.
        $this->revokeFamily($familyId, 'reuse_detected');

        throw new AuthException(
            'Refresh token reuse detected. All sessions for this family have been revoked.',
            'refresh_token_reused',
        );
    }

    /**
     * Hard gate on the account state before re-issuing tokens.
     *
     * Delegates the status column check to AccountStatusService so timed
     * bans auto-unban consistently with the login path. Resolved lazily
     * to avoid a constructor cycle (AccountStatusService depends on us).
     */
    private function assertUserCanRefresh(User $user): void
    {
        if (method_exists($user, 'trashed') && $user->trashed()) {
            throw new AccountInactiveException();
        }

        if (
            (bool) config('auth_system.verification.required_for_refresh', true)
            && method_exists($user, 'hasVerifiedEmail')
            && ! $user->hasVerifiedEmail()
        ) {
            throw new EmailNotVerifiedException();
        }

        app(AccountStatusService::class)->assertCanLogin($user);
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
