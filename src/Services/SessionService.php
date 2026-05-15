<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Services;

use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Joe404\LaravelAuth\Models\AuthRefreshToken;
use Joe404\LaravelAuth\Models\AuthSessionExtended;
use Laravel\Sanctum\PersonalAccessToken;

class SessionService
{
    public function __construct(
        private readonly DeviceService $deviceService,
    ) {}

    public function create(User $user, Request $request, ?int $sanctumTokenId = null): AuthSessionExtended
    {
        /** @var array<string, mixed> $fingerprint */
        $fingerprint = $request->get('_device', []);

        if (empty($fingerprint)) {
            $fingerprint = $this->deviceService->fingerprint($request);
        }

        $sessionId = null;

        if ($request->bearerToken() === null) {
            try {
                $sessionId = $request->session()->getId();
            } catch (\Throwable) {
                $sessionId = null;
            }
        }

        return AuthSessionExtended::create([
            'user_id'               => $user->getKey(),
            'session_id'            => $sessionId,
            'sanctum_token_id'      => $sanctumTokenId,
            'platform'              => $fingerprint['platform'] ?? 'web',
            'browser'               => $fingerprint['browser'] ?? null,
            'os'                    => $fingerprint['os'] ?? null,
            'device_model'          => $fingerprint['device_model'] ?? null,
            'device_marketing_name' => $fingerprint['device_marketing_name'] ?? null,
            'device_code'           => $fingerprint['device_code'] ?? null,
            'device_platform'       => $fingerprint['device_platform'] ?? null,
            'ip_address'            => $fingerprint['ip_address'] ?? $request->ip(),
            'country'               => $fingerprint['country'] ?? null,
            'city'                  => $fingerprint['city'] ?? null,
            'last_active_at'        => now(),
        ]);
    }

    public function listForUser(User $user, Request $request): Collection
    {
        $sessions = AuthSessionExtended::where('user_id', $user->getKey())
            ->latest('last_active_at')
            ->get();

        return $sessions->map(function (AuthSessionExtended $session) use ($request): AuthSessionExtended {
            $session->setAttribute('is_current', $session->isCurrent($request));

            return $session;
        });
    }

    public function delete(User $user, int $sessionId): void
    {
        $session = AuthSessionExtended::where('id', $sessionId)
            ->where('user_id', $user->getKey())
            ->first();

        if ($session === null) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Session not found.');
        }

        if ($session->sanctum_token_id !== null) {
            AuthRefreshToken::where('access_token_id', $session->sanctum_token_id)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now(), 'revoked_reason' => 'session_deleted']);
            PersonalAccessToken::find($session->sanctum_token_id)?->delete();
        }

        $session->delete();
    }

    public function deleteAll(User $user, ?int $exceptId = null): void
    {
        // Snapshot the access-token IDs we are about to revoke. Doing this in
        // one read avoids a TOCTOU window where a concurrent login could
        // create a session between read and delete and end up with its
        // refresh/access pair orphaned.
        $tokenIds = AuthSessionExtended::where('user_id', $user->getKey())
            ->when($exceptId !== null, fn ($q) => $q->where('id', '!=', $exceptId))
            ->whereNotNull('sanctum_token_id')
            ->pluck('sanctum_token_id')
            ->all();

        if ($tokenIds !== []) {
            AuthRefreshToken::whereIn('access_token_id', $tokenIds)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now(), 'revoked_reason' => 'logout_all']);

            PersonalAccessToken::whereIn('id', $tokenIds)->delete();
        }

        AuthSessionExtended::where('user_id', $user->getKey())
            ->when($exceptId !== null, fn ($q) => $q->where('id', '!=', $exceptId))
            ->delete();
    }

    public function touch(Request $request): void
    {
        $user = $request->user();

        if ($user === null) {
            return;
        }

        $query = AuthSessionExtended::where('user_id', $user->getKey());

        $accessToken = $user->currentAccessToken();

        if ($accessToken instanceof \Laravel\Sanctum\PersonalAccessToken) {
            $query->where('sanctum_token_id', $accessToken->id);
        } else {
            try {
                $query->where('session_id', $request->session()->getId());
            } catch (\Throwable) {
                return;
            }
        }

        $query->update(['last_active_at' => now()]);
    }
}
