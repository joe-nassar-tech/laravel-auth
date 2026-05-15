<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Joe404\LaravelAuth\Events\UserLoggedIn;
use Joe404\LaravelAuth\Exceptions\AccountInactiveException;
use Joe404\LaravelAuth\Exceptions\AuthException;
use Joe404\LaravelAuth\Models\AuthSocialAccount;
use Joe404\LaravelAuth\Notifications\SocialLinkConfirmationNotification;
use Laravel\Socialite\Facades\Socialite;

class SocialAuthService
{
    public function __construct(
        private readonly TokenService $tokenService,
        private readonly SessionService $sessionService,
    ) {}

    public function redirectUrl(string $provider, Request $request): string
    {
        if (! config("auth_system.social.{$provider}.enabled", false)) {
            throw new AuthException(ucfirst($provider) . ' authentication is not enabled.', 'social_provider_disabled', ['provider' => ucfirst($provider)]);
        }

        $driver = Socialite::driver($provider);

        if ($this->resolveClientType($request) !== null) {
            $driver = $driver->stateless();
        }

        return $driver->redirect()->getTargetUrl();
    }

    /**
     * @return array{
     *   status: 'logged_in'|'requires_link_confirmation',
     *   user?: array<string, mixed>,
     *   token?: ?string,
     *   refresh_token?: ?string,
     *   email?: string,
     * }
     */
    public function handleCallback(string $provider, Request $request): array
    {
        if (! config("auth_system.social.{$provider}.enabled", false)) {
            throw new AuthException(ucfirst($provider) . ' authentication is not enabled.', 'social_provider_disabled', ['provider' => ucfirst($provider)]);
        }

        try {
            $driver = Socialite::driver($provider);

            if ($this->resolveClientType($request) !== null) {
                $driver = $driver->stateless();
            }

            $socialUser = $driver->user();
        } catch (\Throwable) {
            throw new AuthException('Unable to authenticate with ' . ucfirst($provider) . '. Please try again.', 'social_authentication_failed', ['provider' => ucfirst($provider)]);
        }

        // 1. Existing social account → log in directly.
        $socialAccount = AuthSocialAccount::where('provider', $provider)
            ->where('provider_id', $socialUser->getId())
            ->with('user')
            ->first();

        if ($socialAccount !== null) {
            return ['status' => 'logged_in'] + $this->loginSocialUser($socialAccount->user, $request);
        }

        $userModel = config('auth.providers.users.model', \App\Models\User::class);
        $email     = $socialUser->getEmail() ?? '';
        $existing  = $email !== '' ? $userModel::where('email', $email)->first() : null;

        if ($existing !== null) {
            // 2. Email already exists locally but no social link yet.
            //
            // We must NOT auto-link based on email match alone — Google's
            // "verified email" claim is per-provider, and even when accurate,
            // auto-linking lets anyone who controls a provider account with
            // a victim's email take over the local account silently.
            //
            // Instead: send a one-time confirmation link to the registered
            // email and stash the social profile against a short-lived token.
            // Only when the legitimate inbox owner clicks the link is the
            // social account actually linked.
            $token = $this->stashLinkRequest($provider, $existing->getKey(), $socialUser);

            $existing->notify(new SocialLinkConfirmationNotification($provider, $token));

            return [
                'status' => 'requires_link_confirmation',
                'email'  => $email,
            ];
        }

        // 3. Brand new user — only allow if Google flagged email as verified.
        if (method_exists($socialUser, 'getRaw')) {
            $raw = $socialUser->getRaw();
            if (isset($raw['email_verified']) && $raw['email_verified'] === false) {
                throw new AuthException('The email associated with your Google account is not verified.', 'social_email_unverified', ['provider' => 'Google']);
            }
        }

        $newUser = $this->createUserFromSocial($provider, $socialUser);
        $this->createSocialAccount($newUser, $provider, $socialUser);

        return ['status' => 'logged_in'] + $this->loginSocialUser($newUser, $request);
    }

    /**
     * Confirm a pending link-account request and complete the login.
     *
     * @return array{user: array<string, mixed>, token: ?string, refresh_token: ?string}
     */
    public function confirmLink(string $token, Request $request): array
    {
        $payload = Cache::pull("auth:social_link:{$token}");

        if ($payload === null) {
            throw new AuthException('Invalid or expired confirmation link.', 'social_link_token_invalid');
        }

        $userModel = config('auth.providers.users.model', \App\Models\User::class);
        $user      = $userModel::find($payload['user_id']);

        if ($user === null) {
            throw new AuthException('User not found.', 'social_user_not_found');
        }

        // Re-check no row was created between the email click and now.
        $existingLink = AuthSocialAccount::where('provider', $payload['provider'])
            ->where('provider_id', $payload['provider_id'])
            ->first();

        if ($existingLink === null) {
            AuthSocialAccount::create([
                'user_id'        => $user->getKey(),
                'provider'       => $payload['provider'],
                'provider_id'    => $payload['provider_id'],
                'provider_email' => $payload['provider_email'],
                'avatar'         => $payload['avatar'],
            ]);
        }

        return $this->loginSocialUser($user, $request);
    }

    private function stashLinkRequest(string $provider, mixed $userId, object $socialUser): string
    {
        $token = Str::uuid()->toString();

        Cache::put("auth:social_link:{$token}", [
            'provider'       => $provider,
            'provider_id'    => $socialUser->getId(),
            'provider_email' => $socialUser->getEmail(),
            'avatar'         => $socialUser->getAvatar(),
            'user_id'        => $userId,
        ], now()->addMinutes(15));

        return $token;
    }

    private function createUserFromSocial(string $provider, object $socialUser): mixed
    {
        $userModel = config('auth.providers.users.model', \App\Models\User::class);
        $email     = $socialUser->getEmail() ?? '';
        $name      = $socialUser->getName() ?: Str::before($email, '@');

        $user = $userModel::create([
            'name'              => $name,
            'email'             => $email,
            'password'          => Hash::make(Str::random(64)),
            'email_verified_at' => now(),
        ]);

        $defaultRole = (string) config('auth_system.roles.default_role', 'user');

        if (method_exists($user, 'assignRole')) {
            $user->assignRole($defaultRole);
        }

        return $user;
    }

    private function createSocialAccount(mixed $user, string $provider, object $socialUser): AuthSocialAccount
    {
        return AuthSocialAccount::create([
            'user_id'        => $user->getKey(),
            'provider'       => $provider,
            'provider_id'    => $socialUser->getId(),
            'provider_email' => $socialUser->getEmail(),
            'avatar'         => $socialUser->getAvatar(),
        ]);
    }

    /**
     * @return array{user: array<string, mixed>, token: ?string, refresh_token: ?string}
     */
    private function loginSocialUser(mixed $user, Request $request): array
    {
        if (isset($user->is_active) && ! $user->is_active) {
            throw new AccountInactiveException();
        }

        $user->update(['last_login_at' => now()]);

        UserLoggedIn::dispatch($user, $request);

        $clientType = $this->resolveClientType($request);

        if ($clientType !== null) {
            $tokenData      = $this->tokenService->issue($user, $clientType);
            $sanctumTokenId = $tokenData['token']->id;
            $this->sessionService->create($user, $request, $sanctumTokenId);

            return [
                'user'          => $user->toArray(),
                'token'         => $tokenData['plain_text_token'],
                'refresh_token' => $tokenData['plain_refresh_token'],
            ];
        }

        Auth::login($user);
        $request->session()->regenerate();
        $this->sessionService->create($user, $request, null);

        return [
            'user'          => $user->toArray(),
            'token'         => null,
            'refresh_token' => null,
        ];
    }

    private function resolveClientType(Request $request): ?string
    {
        $mode = (string) config('auth_system.mode', 'both');

        if ($mode === 'web') {
            return null;
        }

        if ($mode === 'api') {
            return 'api';
        }

        if (strtolower($request->header('X-Client-Type', '')) === 'mobile') {
            return 'mobile';
        }

        if ((bool) config('auth_system.spa_token', false)) {
            return 'spa';
        }

        return null;
    }
}
