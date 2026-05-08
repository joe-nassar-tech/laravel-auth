<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Joe404\LaravelAuth\Events\UserLoggedIn;
use Joe404\LaravelAuth\Exceptions\AccountInactiveException;
use Joe404\LaravelAuth\Exceptions\AuthException;
use Joe404\LaravelAuth\Models\AuthSocialAccount;
use Laravel\Socialite\Facades\Socialite;

class SocialAuthService
{
    public function __construct(
        private readonly TokenService $tokenService,
        private readonly SessionService $sessionService,
    ) {}

    public function redirectUrl(string $provider): string
    {
        if (! config("auth_system.social.{$provider}.enabled", false)) {
            throw new AuthException(ucfirst($provider) . ' authentication is not enabled.');
        }

        return Socialite::driver($provider)->stateless()->redirect()->getTargetUrl();
    }

    public function handleCallback(string $provider, Request $request): array
    {
        if (! config("auth_system.social.{$provider}.enabled", false)) {
            throw new AuthException(ucfirst($provider) . ' authentication is not enabled.');
        }

        try {
            $socialUser = Socialite::driver($provider)->stateless()->user();
        } catch (\Throwable $e) {
            throw new AuthException('Unable to authenticate with ' . ucfirst($provider) . '. Please try again.');
        }

        // 1. Look up by provider + provider_id
        $socialAccount = AuthSocialAccount::where('provider', $provider)
            ->where('provider_id', $socialUser->getId())
            ->with('user')
            ->first();

        if ($socialAccount !== null) {
            return $this->loginSocialUser($socialAccount->user, $request);
        }

        // 2. Email already exists → link account
        $userModel = config('auth.providers.users.model', \App\Models\User::class);
        $email     = $socialUser->getEmail() ?? '';
        $existing  = $email !== '' ? $userModel::where('email', $email)->first() : null;

        if ($existing !== null) {
            $this->createSocialAccount($existing, $provider, $socialUser);

            return $this->loginSocialUser($existing, $request);
        }

        // 3. Brand new user
        $newUser = $this->createUserFromSocial($provider, $socialUser);
        $this->createSocialAccount($newUser, $provider, $socialUser);

        return $this->loginSocialUser($newUser, $request);
    }

    private function createUserFromSocial(string $provider, \Laravel\Socialite\Contracts\User $socialUser): mixed
    {
        $userModel = config('auth.providers.users.model', \App\Models\User::class);
        $email     = $socialUser->getEmail() ?? '';
        $name      = $socialUser->getName() ?: Str::before($email, '@');

        $user = $userModel::create([
            'name'              => $name,
            'email'             => $email,
            'password'          => null,
            'email_verified_at' => now(),
            'google_id'         => $provider === 'google' ? $socialUser->getId() : null,
        ]);

        $defaultRole = (string) config('auth_system.roles.default_role', 'user');

        if (method_exists($user, 'assignRole')) {
            $user->assignRole($defaultRole);
        }

        return $user;
    }

    private function createSocialAccount(mixed $user, string $provider, \Laravel\Socialite\Contracts\User $socialUser): AuthSocialAccount
    {
        return AuthSocialAccount::create([
            'user_id'        => $user->getKey(),
            'provider'       => $provider,
            'provider_id'    => $socialUser->getId(),
            'provider_email' => $socialUser->getEmail(),
            'avatar'         => $socialUser->getAvatar(),
        ]);
    }

    private function loginSocialUser(mixed $user, Request $request): array
    {
        if (isset($user->is_active) && ! $user->is_active) {
            throw new AccountInactiveException();
        }

        $user->update(['last_login_at' => now()]);

        $tokenData = $this->tokenService->issue($user, 'social-auth-token');

        $sanctumTokenId = $tokenData['token']->id ?? null;
        $this->sessionService->create($user, $request, $sanctumTokenId);

        UserLoggedIn::dispatch($user, $request);

        return [
            'user'  => $user->toArray(),
            'token' => $tokenData['plain_text_token'],
        ];
    }
}
