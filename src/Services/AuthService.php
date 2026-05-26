<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Services;

use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Joe404\LaravelAuth\Events\EmailVerified;
use Joe404\LaravelAuth\Events\PasswordChanged;
use Joe404\LaravelAuth\Events\RegistrationEmailVerified;
use Joe404\LaravelAuth\Events\SuspiciousLoginDetected;
use Joe404\LaravelAuth\Events\TwoFactorChallengeIssued;
use Joe404\LaravelAuth\Events\TwoFactorVerified;
use Joe404\LaravelAuth\Events\UserLoggedIn;
use Joe404\LaravelAuth\Events\UserLoggedOut;
use Joe404\LaravelAuth\Exceptions\AccountInactiveException;
use Joe404\LaravelAuth\Exceptions\AuthException;
use Joe404\LaravelAuth\Exceptions\EmailNotVerifiedException;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Joe404\LaravelAuth\Models\AuthRefreshToken;
use Joe404\LaravelAuth\Models\AuthSessionExtended;
use Joe404\LaravelAuth\Models\DeletedAccount;
use Joe404\LaravelAuth\Support\AccountStatus;
use Laravel\Sanctum\PersonalAccessToken;

class AuthService
{
    public function __construct(
        private readonly OtpService $otpService,
        private readonly TokenService $tokenService,
        private readonly SessionService $sessionService,
        private readonly LockoutService $lockoutService,
        private readonly RateLimitService $rateLimitService,
        private readonly AccountStatusService $accountStatusService,
        private readonly AccountDeletionService $accountDeletionService,
        private readonly ReferralService $referralService,
        private readonly TwoFactorService $twoFactorService,
        private readonly TwoFactorChallengeService $twoFactorChallengeService,
        private readonly TrustedDeviceService $trustedDeviceService,
    ) {}

    public function initiateRegistration(string $email, array $extraFields = []): array
    {
        $email = strtolower(trim($email));

        // Pull out the optional incoming referral code BEFORE running the
        // extra-field transformer pipeline — we don't want a transformer
        // accidentally stripping it, and we cache it separately so the
        // schema for transformers stays purely "user attributes".
        $incomingReferral = null;
        if (isset($extraFields['referral_code']) && is_string($extraFields['referral_code'])) {
            $incomingReferral = trim($extraFields['referral_code']);
            unset($extraFields['referral_code']);
        }

        $extraFields = $this->applyExtraFieldTransformers(
            $this->stripPrivilegedFields($extraFields),
            $email,
        );

        /** @var class-string<User> $userModel */
        $userModel = config('auth.providers.users.model', \App\Models\User::class);

        $tempToken = Str::uuid()->toString();
        $method    = (string) config('auth_system.verification.method', 'both');
        $existing  = $userModel::where('email', $email)->first();

        if ($existing === null) {
            // Cache extra fields only — password is collected AFTER email ownership
            // is proven to prevent pre-account takeover via attacker-initiated
            // registration.
            Cache::put(
                "auth:pending:{$email}",
                [
                    'extra'         => $extraFields,
                    'referral_code' => $incomingReferral,
                ],
                now()->addMinutes((int) config('auth_system.password.pending_ttl_minutes', 60)),
            );

            if ($method === 'both') {
                $this->otpService->sendCombined($email, 'email_verify', 'magic_link_verify', $tempToken);
            } elseif ($method === 'otp') {
                $this->otpService->sendOtp($email, 'email_verify', $tempToken);
            } elseif ($method === 'magic_link') {
                $this->otpService->sendMagicLink($email, 'magic_link_verify', $tempToken);
            }
        } else {
            // Email already registered. We return the same response shape to
            // prevent enumeration. Send a "you already have an account" email
            // out-of-band instead of an OTP — verifying it would be a no-op
            // and only the legitimate owner can read the inbox anyway.
            $this->dispatchExistingAccountEmail($existing);
        }

        // Constant-time noise: the existing-email branch does roughly the same
        // amount of work as the new-email branch (one DB lookup, one notification
        // dispatch), so timing does not leak which path was taken.

        return [
            'temp_token' => $tempToken,
            'method'     => $method,
            'expires_in' => (int) config('auth_system.verification.otp_expiry', 10),
        ];
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private function stripPrivilegedFields(array $fields): array
    {
        $denylist = ['role', 'roles', 'is_admin', 'admin', 'email_verified_at', 'password', 'password_change_required'];

        foreach ($denylist as $key) {
            unset($fields[$key]);
        }

        return $fields;
    }

    /**
     * Run configured extra-field transformers and merge their output into the
     * extra fields array. Each transformer receives the full validated input
     * (extras + email) and returns the value to store under its target key.
     *
     * Configure via auth_system.registration.extra_fields_transformers:
     *   'extra_fields_transformers' => [
     *       'username_normalized' => \App\Transformers\UsernameNormalizer::class,
     *   ],
     *
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

    private function dispatchExistingAccountEmail(User $user): void
    {
        try {
            $user->notify(new \Joe404\LaravelAuth\Notifications\ExistingAccountNotification());
        } catch (\Throwable) {
            // Notifications failing must not leak existence via differing response.
        }
    }

    public function completeRegistrationWithOtp(string $email, string $code): array
    {
        $otpRecord = $this->otpService->validateOtp($email, $code, 'email_verify');

        $result = $this->issueCompletionToken($otpRecord->email);

        RegistrationEmailVerified::dispatch(
            (string) $otpRecord->temp_token,
            (string) $result['completion_token'],
            (string) $otpRecord->email,
        );

        return $result;
    }

    public function completeRegistrationWithMagicLink(string $token): array
    {
        $otpRecord = $this->otpService->validateMagicLink($token, 'magic_link_verify');

        $result = $this->issueCompletionToken($otpRecord->email);

        RegistrationEmailVerified::dispatch(
            (string) $otpRecord->temp_token,
            (string) $result['completion_token'],
            (string) $otpRecord->email,
        );

        return $result;
    }

    private function issueCompletionToken(string $email): array
    {
        $pending = Cache::get("auth:pending:{$email}");

        if ($pending === null) {
            throw new AuthException('Registration session expired. Please start again.', 'registration_session_expired');
        }

        $completionToken = Str::uuid()->toString();

        Cache::put(
            "auth:completion:{$completionToken}",
            [
                'email'         => $email,
                'extra'         => (array) ($pending['extra'] ?? []),
                'referral_code' => $pending['referral_code'] ?? null,
            ],
            now()->addMinutes(15),
        );

        // Verification succeeded — drop the pending entry. Subsequent calls
        // to /auth/email/resend-verification for this email will silently
        // no-op (200 with the same envelope, no mail sent), because there
        // is nothing left to re-verify; the next step is /register/complete.
        Cache::forget("auth:pending:{$email}");

        return ['completion_token' => $completionToken];
    }

    public function finalizeRegistration(string $completionToken, string $plainPassword, Request $request): array
    {
        $data = Cache::pull("auth:completion:{$completionToken}");

        if ($data === null) {
            throw new AuthException('Invalid or expired completion token. Please verify your email again.', 'completion_token_invalid');
        }

        $email             = (string) $data['email'];
        $extraFields       = $this->stripPrivilegedFields((array) ($data['extra'] ?? []));
        $incomingReferral  = isset($data['referral_code']) && is_string($data['referral_code']) && $data['referral_code'] !== ''
            ? $data['referral_code']
            : null;

        // Generate a referral code if the host app has opted in. The column
        // name is configurable so this works regardless of the host's schema.
        if ((bool) config('auth_system.referral_code.enabled', false)) {
            $column = (string) config('auth_system.referral_code.column', 'referral_code');

            // Don't overwrite if a transformer or caller already set it.
            if (! array_key_exists($column, $extraFields) || $extraFields[$column] === null) {
                $extraFields[$column] = app(\Joe404\LaravelAuth\Contracts\ReferralCodeGeneratorContract::class)->generate();
            }
        }

        /** @var class-string<User> $userModel */
        $userModel = config('auth.providers.users.model', \App\Models\User::class);

        $name = (string) Str::before($email, '@');

        // Detect whether the host model has the 'hashed' cast on password.
        $userInstance  = new $userModel;
        $castType      = method_exists($userInstance, 'getCasts') ? ($userInstance->getCasts()['password'] ?? null) : null;
        $passwordValue = ($castType === 'hashed') ? $plainPassword : Hash::make($plainPassword);

        // Atomic create + verify + role-assign — partial failure must not leave
        // half-registered users behind.
        /** @var User $user */
        $user = DB::transaction(function () use ($userModel, $email, $name, $passwordValue, $extraFields): User {
            // Race guard inside the transaction.
            if ($userModel::where('email', $email)->exists()) {
                throw new \DomainException('This email is already registered.');
            }

            /** @var User $user */
            $user = $userModel::create(array_merge([
                'name'     => $name,
                'email'    => $email,
                'password' => $passwordValue,
            ], $extraFields));

            $user->email_verified_at = now();
            $user->save();

            $defaultRole = (string) config('auth_system.roles.default_role', 'user');

            if (method_exists($user, 'assignRole')) {
                $user->assignRole($defaultRole);
            }

            return $user;
        });

        EmailVerified::dispatch($user, $completionToken);

        // Apply the referral code (if one was submitted at /register and
        // the feature is enabled). The session row must already exist
        // before we apply the referral so the referrer-side fingerprint
        // snapshot reads work — but here we are the *new* user and the
        // session is created below. The referral service reads the
        // referrer's session (a different user), so order with the
        // current user's session creation doesn't matter.
        $referralError = null;
        if ($incomingReferral !== null && (bool) config('auth_system.referral_code.enabled', false)) {
            try {
                $this->referralService->applyAtRegistration($user, $incomingReferral, $request);
            } catch (AuthException $e) {
                // Registration must still succeed. We attach the error so
                // the response includes it as metadata and the frontend
                // can show "you registered, but your referral code was
                // not accepted because X".
                $referralError = [
                    'key'     => $e->errorKey(),
                    'message' => $e->getMessage(),
                ];
            } catch (\Throwable) {
                // Never bring down registration over a referral hiccup.
                $referralError = [
                    'key'     => 'referral_unknown_error',
                    'message' => 'Referral could not be applied.',
                ];
            }
        }

        $clientType = $this->resolveClientType($request);
        $tokenData  = [];

        if ($clientType !== null) {
            $tokenData      = $this->tokenService->issue($user, $clientType);
            $sanctumTokenId = $tokenData['token']->id;
            $this->sessionService->create($user, $request, $sanctumTokenId);
        } else {
            Auth::login($user);
            $request->session()->regenerate();
            $this->sessionService->create($user, $request, null);
        }

        // v2.6 — auto-trust the device used to complete registration
        // so the user does not face a 2FA challenge on their first real login.
        // The transient `plain_secret` attribute is forwarded to the client
        // exactly once — they must echo it back as X-Trusted-Device-Token on
        // future logins to bypass 2FA.
        $trustedDevice    = $this->trustedDeviceService->autoTrustRegistrationDevice($user, $request);
        $trustDeviceToken = $trustedDevice?->getAttribute('plain_secret');

        $result = [
            'user'          => $user,
            'token'         => $tokenData['plain_text_token'] ?? null,
            'refresh_token' => $tokenData['plain_refresh_token'] ?? null,
        ];

        if ($trustDeviceToken !== null) {
            $result['trusted_device_token'] = $trustDeviceToken;
        }

        if ($referralError !== null) {
            $result['referral_error'] = $referralError;
        }

        return $result;
    }

    public function login(string $email, string $password, Request $request): array
    {
        $email = strtolower(trim($email));

        $this->lockoutService->throwIfLockedOut($email);

        /** @var class-string<User> $userModel */
        $userModel = config('auth.providers.users.model', \App\Models\User::class);

        // Include soft-deleted users so we can auto-restore within the grace
        // window. Without this, a user who deleted their account would see
        // "invalid credentials" instead of being silently restored.
        $query = $userModel::query();
        if (in_array(SoftDeletes::class, class_uses_recursive($userModel), true)) {
            /** @phpstan-ignore-next-line */
            $query = $query->withTrashed();
        }

        /** @var User|null $user */
        $user = $query->where('email', $email)->first();

        if ($user === null || ! Hash::check($password, $user->password)) {
            $this->lockoutService->recordFailure($email);
            throw new AuthException('Invalid credentials.', 'invalid_credentials');
        }

        // v2.4 — Auto-restore on login during grace period. Credentials match,
        // status is "deleted", and the audit row says we're still within grace
        // → restore silently and continue the normal login flow.
        $statusEnabled = (bool) config('auth_system.account.status.enabled', true);
        $autoRestore   = (bool) config('auth_system.account.deletion.auto_restore_on_login', true);

        if ($statusEnabled
            && $autoRestore
            && $this->accountStatusService->current($user) === AccountStatus::DELETED
        ) {
            $entry = DeletedAccount::where('original_user_id', $user->getKey())
                ->whereNull('purged_at')
                ->latest('id')
                ->first();

            if ($entry !== null && $entry->isWithinGrace()) {
                $this->accountDeletionService->restore($user, 'login');
                $user->refresh();
            } else {
                // Grace expired (or no audit row) — treat as gone.
                $this->lockoutService->recordFailure($email);
                throw new AuthException('Invalid credentials.', 'invalid_credentials');
            }
        }

        // v2.4 — Auto-reactivate "deactivated" (Instagram-style pause).
        // Distinct from the deleted-grace branch above: no audit row to drop,
        // no SoftDeletes to clear, no deadline. The user simply comes back.
        if ($statusEnabled
            && (bool) config('auth_system.account.deactivation.auto_reactivate_on_login', true)
            && $this->accountStatusService->current($user) === AccountStatus::DEACTIVATED
        ) {
            $this->accountStatusService->changeStatus(
                $user,
                AccountStatus::ACTIVE,
                'Auto-reactivate on login.',
                null,
                ['actor_type' => 'system', 'source' => 'login_auto_reactivate'],
            );

            if ((bool) config('auth_system.mail.account_notifications_enabled.reactivated', true)) {
                $this->dispatchReactivatedNotification($user);
            }

            $user->refresh();
        }

        // Status gate (disabled/suspended). Throws AuthException with a
        // per-status error key resolved through the same translation pipeline.
        if ($statusEnabled) {
            $this->accountStatusService->assertCanLogin($user);
        }

        if (isset($user->is_active) && ! $user->is_active) {
            throw new AccountInactiveException();
        }

        $requireVerification = (bool) config('auth_system.require_email_verification', true);

        if ($requireVerification && method_exists($user, 'hasVerifiedEmail') && ! $user->hasVerifiedEmail()) {
            throw new EmailNotVerifiedException();
        }

        $this->lockoutService->clear($email);

        // Successful login → clear the rate-limit counter for this IP/email so
        // the user is not locked out by their own legitimate logins.
        $this->rateLimitService->clear('login', (string) $request->ip());
        $this->rateLimitService->clear('login', $email);

        $isNewDevice = $this->isNewDevice($user, $request);

        $clientType = $this->resolveClientType($request);

        // v2.6 — 2FA gate. If the user has at least one verified 2FA method
        // and the current device is NOT a trusted device at the bypass level,
        // we short-circuit before issuing any real session/token: return a
        // challenge_token instead and let the client call /auth/2fa/challenge.
        $twoFactorEnabled = (bool) config('auth_system.two_factor.enabled', true);

        if ($twoFactorEnabled
            && $this->twoFactorService->hasAnyVerifiedMethod($user)
            && ! $this->trustedDeviceService->shouldBypass2fa($user, $request)
        ) {
            // Credential success still updates last_login_at and dispatches the
            // pre-v2.6 UserLoggedIn event — listeners that audit "user passed
            // the password check" continue to fire even when the user does
            // not complete 2FA. TwoFactorVerified is dispatched separately
            // by completeTwoFactorChallenge() on the second leg.
            $user->update(['last_login_at' => now()]);
            UserLoggedIn::dispatch($user, $request);

            $challenge = $this->twoFactorChallengeService->createForUser($user, $clientType, $request);

            TwoFactorChallengeIssued::dispatch(
                $user,
                (string) $challenge['challenge_token'],
                (string) $challenge['method'],
                (bool) ($challenge['reused'] ?? false),
            );

            return [
                'requires_2fa'      => true,
                'challenge_token'   => $challenge['challenge_token'],
                'method'            => $challenge['method'],
                'available_methods' => $challenge['available_methods'],
                'masked_target'     => $challenge['masked_target'],
                'expires_in'        => $challenge['expires_in'],
            ];
        }

        $user->update(['last_login_at' => now()]);

        UserLoggedIn::dispatch($user, $request);

        if ($clientType !== null) {
            $tokenData = $this->tokenService->issue($user, $clientType);

            $sanctumTokenId = $tokenData['token']->id;
            $this->sessionService->create($user, $request, $sanctumTokenId);

            if ($isNewDevice) {
                $this->dispatchSuspiciousLogin($user, $request);
            }

            return [
                'user'          => $user->toArray(),
                'token'         => $tokenData['plain_text_token'],
                'refresh_token' => $tokenData['plain_refresh_token'],
            ];
        }

        Auth::login($user);
        $request->session()->regenerate();

        $this->sessionService->create($user, $request, null);

        if ($isNewDevice) {
            $this->dispatchSuspiciousLogin($user, $request);
        }

        return [
            'user'          => $user->toArray(),
            'token'         => null,
            'refresh_token' => null,
        ];
    }

    /**
     * Complete a 2FA challenge and finish login. Mirrors the post-credential
     * branch of login() so the response shape matches.
     */
    public function completeTwoFactorChallenge(
        string $challengeToken,
        string $code,
        ?string $methodHint,
        bool $trustDevice,
        Request $request,
    ): array {
        $user = $this->twoFactorChallengeService->verify($challengeToken, $code, $methodHint);

        // NOTE: last_login_at and UserLoggedIn already fired at credential
        // success inside login() (the gate that issued this challenge). This
        // leg only confirms the second factor — re-dispatching UserLoggedIn
        // here would double-fire every login listener. We dispatch
        // TwoFactorVerified instead, and surface the new-device alert that
        // the gate deferred (the device is "new" until this session row
        // exists, which we create below).
        $clientType  = $this->resolveClientType($request);
        $isNewDevice = $this->isNewDevice($user, $request);

        $trustDeviceToken = null;
        if ($trustDevice) {
            $trustedDeviceRecord = $this->trustedDeviceService->trustCurrent($user, $request);
            $trustDeviceToken    = $trustedDeviceRecord?->getAttribute('plain_secret');
        } else {
            $this->trustedDeviceService->touch($user, $request);
        }

        TwoFactorVerified::dispatch($user, (string) ($methodHint ?? 'unknown'));

        if ($clientType !== null) {
            $tokenData = $this->tokenService->issue($user, $clientType);

            $sanctumTokenId = $tokenData['token']->id;
            $this->sessionService->create($user, $request, $sanctumTokenId);

            $this->stamp2faVerified((int) $user->getKey(), $sanctumTokenId);

            if ($isNewDevice) {
                $this->dispatchSuspiciousLogin($user, $request);
            }

            $result = [
                'user'          => $user->toArray(),
                'token'         => $tokenData['plain_text_token'],
                'refresh_token' => $tokenData['plain_refresh_token'],
            ];

            if ($trustDeviceToken !== null) {
                $result['trusted_device_token'] = $trustDeviceToken;
            }

            return $result;
        }

        Auth::login($user);
        $request->session()->regenerate();
        $this->sessionService->create($user, $request, null);

        $this->stamp2faVerified((int) $user->getKey(), $request->session()->getId());

        if ($isNewDevice) {
            $this->dispatchSuspiciousLogin($user, $request);
        }

        $result = [
            'user'          => $user->toArray(),
            'token'         => null,
            'refresh_token' => null,
        ];

        if ($trustDeviceToken !== null) {
            $result['trusted_device_token'] = $trustDeviceToken;
        }

        return $result;
    }

    private function stamp2faVerified(int $userId, int|string|null $sessionOrTokenId): void
    {
        $ttl = max(1, (int) config('auth_system.two_factor.sudo_ttl_minutes', 15));

        Cache::put(
            "auth:2fa:stamp:{$userId}:" . (string) $sessionOrTokenId,
            now()->toIso8601String(),
            now()->addMinutes($ttl),
        );
    }

    public function refreshToken(string $rawRefreshToken, Request $request): array
    {
        $clientType = $this->resolveClientType($request) ?? 'mobile';
        $tokenData  = $this->tokenService->refresh($rawRefreshToken, $clientType);

        // Repoint the active session row at the new access token so session
        // listings, /auth/sessions, and revoke-by-session keep working after
        // rotation. Falls back to creating a row when none exists (rotation
        // through a client that never went through the session-creating
        // login path on this device).
        $previousTokenId = $tokenData['previous_token_id'] ?? null;
        $newTokenId      = $tokenData['token']->id;

        $session = $previousTokenId !== null
            ? AuthSessionExtended::where('user_id', $tokenData['user']->getKey())
                ->where('sanctum_token_id', $previousTokenId)
                ->first()
            : null;

        if ($session !== null) {
            $session->update([
                'sanctum_token_id' => $newTokenId,
                'last_active_at'   => now(),
            ]);
        } else {
            $this->sessionService->create($tokenData['user'], $request, $newTokenId);
        }

        return [
            'user'          => $tokenData['user']->toArray(),
            'token'         => $tokenData['plain_text_token'],
            'refresh_token' => $tokenData['plain_refresh_token'],
        ];
    }

    private function isNewDevice(User $user, Request $request): bool
    {
        if (! (bool) config('auth_system.security.notify_new_device_login', true)) {
            return false;
        }

        /** @var array<string, mixed> $fingerprint */
        $fingerprint = $request->get('_device', []);

        $platform   = $fingerprint['platform']    ?? null;
        $browser    = $fingerprint['browser']     ?? null;
        $os         = $fingerprint['os']          ?? null;
        $deviceCode = $fingerprint['device_code'] ?? null;

        // Mobile path: device_code (model identifier) is the strongest signal.
        // Same-physical-device login is not "new" even if the OS upgraded.
        if ($deviceCode !== null && $deviceCode !== '') {
            return ! AuthSessionExtended::where('user_id', $user->getKey())
                ->where('device_code', $deviceCode)
                ->exists();
        }

        // Web path: browser + os + platform, all required to match. This is
        // stricter than the pre-v2 browser+os check (which collided whenever
        // a user opened any browser on an unfamiliar machine).
        if ($browser === null && $os === null && $platform === null) {
            return false;
        }

        return ! AuthSessionExtended::where('user_id', $user->getKey())
            ->where('platform', $platform)
            ->where('browser', $browser)
            ->where('os', $os)
            ->exists();
    }

    private function dispatchReactivatedNotification(User $user): void
    {
        $class = (string) (config('auth_system.mail.account_reactivated_notification')
            ?: \Joe404\LaravelAuth\Notifications\AccountReactivatedNotification::class);

        if (! class_exists($class)) {
            return;
        }

        try {
            \Illuminate\Support\Facades\Notification::send($user, new $class());
        } catch (\Throwable) {
            // Reactivation must not block login if the mailer is misconfigured.
        }
    }

    private function dispatchSuspiciousLogin(User $user, Request $request): void
    {
        /** @var array<string, mixed> $fingerprint */
        $fingerprint = $request->get('_device', []);

        SuspiciousLoginDetected::dispatch(
            $user,
            (string) ($fingerprint['ip_address'] ?? $request->ip()),
            $fingerprint['browser'] ?? null,
            $fingerprint['os'] ?? null,
            $fingerprint['city'] ?? null,
            $fingerprint['country'] ?? null,
        );
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

        // both mode
        if (strtolower($request->header('X-Client-Type', '')) === 'mobile') {
            return 'mobile';
        }

        if ((bool) config('auth_system.spa_token', false)) {
            return 'spa';
        }

        return null;
    }

    public function logout(Request $request): void
    {
        $accessToken = $request->user()?->currentAccessToken();

        // Real Bearer token (PersonalAccessToken) — not a TransientToken from
        // SPA cookie auth. Sanctum returns the latter when the user is
        // authenticated via the session guard, and it has no `id` / no usable
        // `delete()`, so we must guard with an instanceof check.
        if ($accessToken instanceof PersonalAccessToken) {
            $tokenId = $accessToken->id;

            // Revoke (don't hard-delete) the paired refresh token before
            // deleting the access token. Keeping the row with revoked_at set
            // means a leaked-and-replayed refresh token still trips reuse
            // detection instead of looking like an unknown token.
            AuthRefreshToken::where('access_token_id', $tokenId)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now(), 'revoked_reason' => 'logout']);
            AuthSessionExtended::where('sanctum_token_id', $tokenId)->delete();

            $accessToken->delete();
        } else {
            try {
                AuthSessionExtended::where('session_id', $request->session()->getId())->delete();
            } catch (\Throwable) {}

            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        UserLoggedOut::dispatch();
    }

    public function logoutAll(User $user, Request $request): void
    {
        $currentTokenId   = null;
        $currentSessionId = null;

        $accessToken = $request->user()?->currentAccessToken();

        if ($accessToken instanceof PersonalAccessToken) {
            $currentTokenId   = $accessToken->id;
            $currentSession   = AuthSessionExtended::where('sanctum_token_id', $currentTokenId)->first();
            $currentSessionId = $currentSession?->id;
        } else {
            try {
                $currentSession   = AuthSessionExtended::where('session_id', $request->session()->getId())->first();
                $currentSessionId = $currentSession?->id;
            } catch (\Throwable) {}
        }

        $this->sessionService->deleteAll($user, $currentSessionId);
        $this->tokenService->revokeAll($user, $currentTokenId);

        UserLoggedOut::dispatch();
    }

    public function me(User $user): array
    {
        return [
            'user'            => $user,
            'roles'           => method_exists($user, 'getRoleNames') ? $user->getRoleNames() : [],
            'permissions'     => method_exists($user, 'getAllPermissions')
                ? $user->getAllPermissions()->pluck('name')
                : [],
            'active_sessions' => AuthSessionExtended::where('user_id', $user->getKey())->count(),
        ];
    }

    public function forgotPassword(string $email): void
    {
        $email     = strtolower(trim($email));
        $userModel = config('auth.providers.users.model', \App\Models\User::class);
        $user      = $userModel::where('email', $email)->first();

        // config() returns the stored null (not the fallback) when the key exists with a null value,
        // so we use ?? to explicitly fall back to the verification method when reset method is unset.
        $method = (string) (config('auth_system.password_reset.method') ?? config('auth_system.verification.method', 'both'));

        if ($user !== null) {
            $tempToken = Str::uuid()->toString();

            if ($method === 'both') {
                $this->otpService->sendCombined($email, 'password_reset', 'magic_link_reset', $tempToken);
            } elseif ($method === 'otp') {
                $this->otpService->sendOtp($email, 'password_reset', $tempToken);
            } elseif ($method === 'magic_link') {
                $this->otpService->sendMagicLink($email, 'magic_link_reset', $tempToken);
            }

            return;
        }

        // Constant-time noise: do work comparable to the registered branch so
        // request-timing does not leak whether the email exists. Hash::make is
        // intentionally slow, mirroring the cost of building+sending an OTP row.
        Hash::make(Str::random(16));
    }

    public function verifyResetOtp(string $email, string $otp): string
    {
        $email = strtolower(trim($email));
        $this->otpService->validateOtp($email, $otp, 'password_reset');

        $resetToken = Str::uuid()->toString();

        Cache::put(
            "auth:reset_token:{$resetToken}",
            $email,
            now()->addMinutes(15),
        );

        return $resetToken;
    }

    public function validateResetMagicLink(string $token): string
    {
        $otpRecord  = $this->otpService->validateMagicLink($token, 'magic_link_reset');
        $resetToken = Str::uuid()->toString();

        Cache::put(
            "auth:reset_token:{$resetToken}",
            $otpRecord->email,
            now()->addMinutes(15),
        );

        return $resetToken;
    }

    public function resetPasswordWithToken(string $resetToken, string $newPassword, bool $logoutAll, Request $request): array
    {
        $email = Cache::pull("auth:reset_token:{$resetToken}");

        if ($email === null) {
            throw new AuthException('Invalid or expired reset token. Please request a new one.', 'reset_token_invalid');
        }

        $userModel = config('auth.providers.users.model', \App\Models\User::class);
        $user      = $userModel::where('email', (string) $email)->firstOrFail();

        $user->update(['password' => Hash::make($newPassword)]);

        if ($logoutAll) {
            $this->tokenService->revokeAll($user);
            $this->sessionService->deleteAll($user);
        }

        PasswordChanged::dispatch($user);

        // Auto-login after successful reset using the same client-type detection as login.
        $user->update(['last_login_at' => now()]);
        UserLoggedIn::dispatch($user, $request);

        $isNewDevice = $this->isNewDevice($user, $request);
        $clientType  = $this->resolveClientType($request);

        if ($clientType !== null) {
            $tokenData      = $this->tokenService->issue($user, $clientType);
            $sanctumTokenId = $tokenData['token']->id;
            $this->sessionService->create($user, $request, $sanctumTokenId);

            if ($isNewDevice) {
                $this->dispatchSuspiciousLogin($user, $request);
            }

            return [
                'user'          => $user->toArray(),
                'token'         => $tokenData['plain_text_token'],
                'refresh_token' => $tokenData['plain_refresh_token'],
            ];
        }

        Auth::login($user);
        $request->session()->regenerate();
        $this->sessionService->create($user, $request, null);

        if ($isNewDevice) {
            $this->dispatchSuspiciousLogin($user, $request);
        }

        return [
            'user'          => $user->toArray(),
            'token'         => null,
            'refresh_token' => null,
        ];
    }

    public function changePassword(User $user, string $currentPassword, string $newPassword, bool $logoutAll, Request $request): void
    {
        if (! Hash::check($currentPassword, (string) $user->password)) {
            throw new AuthException('Current password is incorrect.', 'current_password_invalid');
        }

        $user->update(['password' => Hash::make($newPassword)]);

        if ($logoutAll) {
            $accessToken    = $user->currentAccessToken();
            $currentTokenId = $accessToken instanceof PersonalAccessToken ? $accessToken->id : null;

            // Revoke all OTHER Sanctum tokens
            $this->tokenService->revokeAll($user, $currentTokenId);

            // Find current session to preserve it
            $currentSessionId = null;
            if ($currentTokenId !== null) {
                $currentSession   = AuthSessionExtended::where('sanctum_token_id', $currentTokenId)->first();
                $currentSessionId = $currentSession?->id;
            } else {
                try {
                    $currentSession   = AuthSessionExtended::where('session_id', $request->session()->getId())->first();
                    $currentSessionId = $currentSession?->id;
                } catch (\Throwable) {}
            }

            $this->sessionService->deleteAll($user, $currentSessionId);
        }

        PasswordChanged::dispatch($user);
    }
}
