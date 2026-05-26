<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Joe404\LaravelAuth\Exceptions\AccountInactiveException;
use Joe404\LaravelAuth\Exceptions\AuthException;
use Joe404\LaravelAuth\Models\AuthSocialAccount;
use Joe404\LaravelAuth\Notifications\SocialLinkConfirmationNotification;
use Joe404\LaravelAuth\Support\AccountStatus;
use Laravel\Socialite\Facades\Socialite;

class SocialAuthService
{
    public function __construct(
        private readonly TokenService $tokenService,
        private readonly SessionService $sessionService,
        private readonly ReferralService $referralService,
        private readonly AuthService $authService,
        private readonly AccountStatusService $accountStatusService,
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
            return $this->loginSocialUser($socialAccount->user, $request);
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

        // 3a. Profile completion (v2.6). When the host requires custom fields
        // that Google cannot supply, do NOT create the user yet. Stash the
        // verified social profile against a short-lived completion token and
        // ask the frontend to collect the required fields, mirroring the
        // 3-step email flow (no user row exists until completion).
        if ((bool) config('auth_system.social.profile_completion.enabled', false)) {
            $completionToken = $this->stashProfileCompletion($provider, $socialUser);

            return [
                'status'           => 'requires_profile_completion',
                'completion_token' => $completionToken,
                'prefill'          => [
                    'email'  => $email,
                    'name'   => $socialUser->getName(),
                    'avatar' => $socialUser->getAvatar(),
                ],
            ];
        }

        $newUser = $this->createUserFromSocial($provider, $socialUser);
        $this->createSocialAccount($newUser, $provider, $socialUser);

        return $this->loginSocialUser($newUser, $request);
    }

    /**
     * Finalize a social registration: validate-side already done by the
     * SocialRegisterCompleteRequest; here we create the user from the stashed
     * social profile + the submitted required fields, link the social account,
     * and log in. Mirrors AuthService::finalizeRegistration for the OAuth path.
     *
     * @param  array<string, mixed>  $fields  Validated payload (extras + optional referral_code)
     * @return array{status: string, user: array<string,mixed>, token: ?string, refresh_token: ?string, referral_error?: array<string,mixed>}
     */
    public function finalizeSocialRegistration(string $completionToken, array $fields, Request $request): array
    {
        $data = Cache::pull("auth:social_complete:{$completionToken}");

        if ($data === null) {
            throw new AuthException('Invalid or expired completion token. Please sign in with Google again.', 'completion_token_invalid');
        }

        $provider   = (string) $data['provider'];
        $providerId = (string) $data['provider_id'];
        $email      = (string) $data['provider_email'];

        $userModel = config('auth.providers.users.model', \App\Models\User::class);

        // Race guard: an account (or link) may have appeared between callback
        // and completion. If the social account now exists, just log in.
        $existingLink = AuthSocialAccount::where('provider', $provider)
            ->where('provider_id', $providerId)
            ->with('user')
            ->first();

        if ($existingLink !== null) {
            return $this->loginSocialUser($existingLink->user, $request);
        }

        if ($email !== '' && $userModel::where('email', $email)->exists()) {
            throw new AuthException('This email is already registered.', 'email_already_registered');
        }

        // Pull out the optional incoming referral code before treating the
        // rest of the payload as user attributes.
        $incomingReferral = null;
        if (isset($fields['referral_code']) && is_string($fields['referral_code']) && trim($fields['referral_code']) !== '') {
            $incomingReferral = trim($fields['referral_code']);
        }
        unset($fields['referral_code'], $fields['completion_token']);

        $extraFields = $this->applyExtraFieldTransformers(
            $this->stripPrivilegedFields($fields),
            $email,
        );

        // Generate this user's own referral code if the feature is enabled.
        if ((bool) config('auth_system.referral_code.enabled', false)) {
            $column = (string) config('auth_system.referral_code.column', 'referral_code');
            if (! array_key_exists($column, $extraFields) || $extraFields[$column] === null) {
                $extraFields[$column] = app(\Joe404\LaravelAuth\Contracts\ReferralCodeGeneratorContract::class)->generate();
            }
        }

        $name = ($data['name'] ?? '') !== '' ? (string) $data['name'] : Str::before($email, '@');

        /** @var \Illuminate\Foundation\Auth\User $user */
        $user = $userModel::create(array_merge([
            'name'              => $name,
            'email'             => $email,
            'password'          => Hash::make(Str::random(64)),
            'email_verified_at' => now(),
        ], $extraFields));

        $defaultRole = (string) config('auth_system.roles.default_role', 'user');
        if (method_exists($user, 'assignRole')) {
            $user->assignRole($defaultRole);
        }

        AuthSocialAccount::create([
            'user_id'        => $user->getKey(),
            'provider'       => $provider,
            'provider_id'    => $providerId,
            'provider_email' => $email,
            'avatar'         => $data['avatar'] ?? null,
        ]);

        // Apply an incoming referral code (best-effort — never block signup).
        $referralError = null;
        if ($incomingReferral !== null && (bool) config('auth_system.referral_code.enabled', false)) {
            try {
                $this->referralService->applyAtRegistration($user, $incomingReferral, $request);
            } catch (AuthException $e) {
                $referralError = ['key' => $e->errorKey(), 'message' => $e->getMessage()];
            } catch (\Throwable) {
                $referralError = ['key' => 'referral_unknown_error', 'message' => 'Referral could not be applied.'];
            }
        }

        $result = $this->loginSocialUser($user, $request);

        if ($referralError !== null) {
            $result['referral_error'] = $referralError;
        }

        return $result;
    }

    private function stashProfileCompletion(string $provider, object $socialUser): string
    {
        $token = Str::uuid()->toString();
        $ttl   = max(1, (int) config('auth_system.social.profile_completion.ttl_minutes', 15));

        Cache::put("auth:social_complete:{$token}", [
            'provider'       => $provider,
            'provider_id'    => $socialUser->getId(),
            'provider_email' => $socialUser->getEmail() ?? '',
            'name'           => $socialUser->getName() ?? '',
            'avatar'         => $socialUser->getAvatar(),
        ], now()->addMinutes($ttl));

        return $token;
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private function stripPrivilegedFields(array $fields): array
    {
        foreach (['role', 'roles', 'is_admin', 'admin', 'email_verified_at', 'password', 'password_change_required'] as $key) {
            unset($fields[$key]);
        }

        return $fields;
    }

    /**
     * @param  array<string, mixed>  $extraFields
     * @return array<string, mixed>
     */
    private function applyExtraFieldTransformers(array $extraFields, string $email): array
    {
        /** @var array<string, class-string> $transformers */
        $transformers = (array) config('auth_system.registration.extra_fields_transformers', []);

        if ($transformers === []) {
            return $extraFields;
        }

        $input = array_merge($extraFields, ['email' => $email]);

        foreach ($transformers as $targetField => $transformerClass) {
            if (! is_string($transformerClass) || ! class_exists($transformerClass)) {
                continue;
            }

            $instance = app($transformerClass);

            if (! $instance instanceof \Joe404\LaravelAuth\Contracts\ExtraFieldTransformerContract) {
                continue;
            }

            $extraFields[$targetField] = $instance->transform($input);
        }

        return $this->stripPrivilegedFields($extraFields);
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
     * Finish a social sign-in: enforce the SAME account-status gate as a
     * password login (#2), then run the SAME 2FA gate + token issuance (#1)
     * via AuthService::issueOrChallenge. The returned array carries a `status`
     * of `requires_2fa` (challenge envelope) or `logged_in` (token envelope).
     *
     * @return array<string,mixed>
     */
    private function loginSocialUser(mixed $user, Request $request): array
    {
        $this->assertSocialEligibility($user);

        $result = $this->authService->issueOrChallenge($user, $request);
        $result['status'] = ($result['requires_2fa'] ?? false) ? 'requires_2fa' : 'logged_in';

        return $result;
    }

    /**
     * Mirror password login's status gate for the social path: auto-reactivate
     * a self-deactivated account, then reject any login-blocked status
     * (disabled/suspended) and inactive accounts. Without this, social login
     * skipped every status check except `is_active` (#2).
     */
    private function assertSocialEligibility(mixed $user): void
    {
        if (isset($user->is_active) && ! $user->is_active) {
            throw new AccountInactiveException();
        }

        if (! (bool) config('auth_system.account.status.enabled', true)) {
            return;
        }

        if ((bool) config('auth_system.account.deactivation.auto_reactivate_on_login', true)
            && $this->accountStatusService->current($user) === AccountStatus::DEACTIVATED
        ) {
            $this->accountStatusService->changeStatus(
                $user,
                AccountStatus::ACTIVE,
                'Auto-reactivate on social login.',
                null,
                ['actor_type' => 'system', 'source' => 'social_login_auto_reactivate'],
            );
            $user->refresh();
        }

        // Throws AuthException with a per-status error key for disabled/
        // suspended (and any other configured login_blocked status).
        $this->accountStatusService->assertCanLogin($user);
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
