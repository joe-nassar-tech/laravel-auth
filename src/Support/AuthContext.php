<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Joe404\LaravelAuth\Services\TrustLevelResolver;
use Joe404\LaravelAuth\Services\TrustedDeviceService;
use Joe404\LaravelAuth\Services\TwoFactorService;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Computes the auth context exposed via Request::authContext(). Read-only —
 * derived from the current request's user + session/token state.
 */
class AuthContext
{
    /** @return array<string,mixed> */
    public static function forRequest(Request $request): array
    {
        $user = $request->user();

        if ($user === null) {
            return [
                'authenticated'      => false,
                '2fa_enabled'        => false,
                '2fa_verified'       => false,
                '2fa_verified_at'    => null,
                'trust_level'        => null,
                'phone_verified'     => false,
                'sudo_active'        => false,
            ];
        }

        /** @var TwoFactorService $twoFactor */
        $twoFactor = app(TwoFactorService::class);
        /** @var TrustedDeviceService $trustedDevices */
        $trustedDevices = app(TrustedDeviceService::class);
        /** @var TrustLevelResolver $resolver */
        $resolver = app(TrustLevelResolver::class);

        $token = $user->currentAccessToken();
        $id    = $token instanceof PersonalAccessToken ? $token->id : $request->session()?->getId();

        $stamp = Cache::get("auth:2fa:stamp:{$user->getKey()}:" . (string) $id);
        $sudo  = Cache::get("auth:sudo:{$user->getKey()}:" . (string) $id);

        $device     = $trustedDevices->currentActive($user, $request);
        $trustLevel = $device !== null ? $resolver->resolve($device) : null;

        return [
            'authenticated'      => true,
            '2fa_enabled'        => $twoFactor->hasAnyVerifiedMethod($user),
            '2fa_verified'       => $stamp !== null,
            '2fa_verified_at'    => is_string($stamp) ? $stamp : null,
            'trust_level'        => $trustLevel,
            'phone_verified'     => $user->phone_verified_at !== null,
            'sudo_active'        => $sudo === true,
        ];
    }
}
