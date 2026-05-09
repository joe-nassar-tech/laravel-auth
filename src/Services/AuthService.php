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
use Joe404\LaravelAuth\Events\SuspiciousLoginDetected;
use Joe404\LaravelAuth\Events\UserLoggedIn;
use Joe404\LaravelAuth\Events\UserLoggedOut;
use Joe404\LaravelAuth\Exceptions\AccountInactiveException;
use Joe404\LaravelAuth\Exceptions\AuthException;
use Joe404\LaravelAuth\Exceptions\EmailNotVerifiedException;
use Illuminate\Support\Facades\DB;
use Joe404\LaravelAuth\Models\AuthRefreshToken;
use Joe404\LaravelAuth\Models\AuthSessionExtended;

class AuthService
{
    public function __construct(
        private readonly OtpService $otpService,
        private readonly TokenService $tokenService,
        private readonly SessionService $sessionService,
        private readonly LockoutService $lockoutService,
        private readonly RateLimitService $rateLimitService,
    ) {}

    public function initiateRegistration(string $email, array $extraFields = []): array
    {
        $email = strtolower(trim($email));

        $extraFields = $this->stripPrivilegedFields($extraFields);

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
                ['extra' => $extraFields],
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

        return $this->issueCompletionToken($otpRecord->email);
    }

    public function completeRegistrationWithMagicLink(string $token): array
    {
        $otpRecord = $this->otpService->validateMagicLink($token, 'magic_link_verify');

        return $this->issueCompletionToken($otpRecord->email);
    }

    private function issueCompletionToken(string $email): array
    {
        $pending = Cache::get("auth:pending:{$email}");

        if ($pending === null) {
            throw new \RuntimeException('Registration session expired. Please start again.');
        }

        $completionToken = Str::uuid()->toString();

        Cache::put(
            "auth:completion:{$completionToken}",
            ['email' => $email, 'extra' => (array) ($pending['extra'] ?? [])],
            now()->addMinutes(15),
        );

        // Keep the pending entry alive briefly so re-sends still work.
        Cache::forget("auth:pending:{$email}");

        return ['completion_token' => $completionToken];
    }

    public function finalizeRegistration(string $completionToken, string $plainPassword, Request $request): array
    {
        $data = Cache::pull("auth:completion:{$completionToken}");

        if ($data === null) {
            throw new AuthException('Invalid or expired completion token. Please verify your email again.');
        }

        $email       = (string) $data['email'];
        $extraFields = $this->stripPrivilegedFields((array) ($data['extra'] ?? []));

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

        return [
            'user'          => $user,
            'token'         => $tokenData['plain_text_token'] ?? null,
            'refresh_token' => $tokenData['plain_refresh_token'] ?? null,
        ];
    }

    public function login(string $email, string $password, Request $request): array
    {
        $email = strtolower(trim($email));

        $this->lockoutService->throwIfLockedOut($email);

        /** @var class-string<User> $userModel */
        $userModel = config('auth.providers.users.model', \App\Models\User::class);

        /** @var User|null $user */
        $user = $userModel::where('email', $email)->first();

        if ($user === null || ! Hash::check($password, $user->password)) {
            $this->lockoutService->recordFailure($email);
            throw new AuthException('Invalid credentials.');
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

        $user->update(['last_login_at' => now()]);

        UserLoggedIn::dispatch($user, $request);

        $clientType = $this->resolveClientType($request);

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

    public function refreshToken(string $rawRefreshToken, Request $request): array
    {
        $clientType = $this->resolveClientType($request) ?? 'mobile';
        $tokenData  = $this->tokenService->refresh($rawRefreshToken, $clientType);

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
        if ($request->bearerToken() !== null) {
            $tokenId = $request->user()?->currentAccessToken()?->id;

            if ($tokenId !== null) {
                // Revoke (don't hard-delete) the paired refresh token before
                // deleting the access token. Keeping the row with revoked_at set
                // means a leaked-and-replayed refresh token still trips reuse
                // detection instead of looking like an unknown token.
                AuthRefreshToken::where('access_token_id', $tokenId)
                    ->whereNull('revoked_at')
                    ->update(['revoked_at' => now(), 'revoked_reason' => 'logout']);
                AuthSessionExtended::where('sanctum_token_id', $tokenId)->delete();
            }

            $request->user()?->currentAccessToken()?->delete();
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

        if ($request->bearerToken() !== null) {
            $currentTokenId = $request->user()?->currentAccessToken()?->id;

            if ($currentTokenId !== null) {
                $currentSession   = AuthSessionExtended::where('sanctum_token_id', $currentTokenId)->first();
                $currentSessionId = $currentSession?->id;
            }
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

        $method = (string) config('auth_system.password_reset.method', config('auth_system.verification.method', 'both'));

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

    public function resetPasswordWithOtp(string $email, string $otp, string $newPassword): void
    {
        $email = strtolower(trim($email));
        $this->otpService->validateOtp($email, $otp, 'password_reset');

        $userModel = config('auth.providers.users.model', \App\Models\User::class);
        $user      = $userModel::where('email', $email)->firstOrFail();

        $user->update(['password' => Hash::make($newPassword)]);

        $this->tokenService->revokeAll($user);
        $this->sessionService->deleteAll($user);

        PasswordChanged::dispatch($user);
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

    public function resetPasswordWithToken(string $resetToken, string $newPassword): void
    {
        $email = Cache::pull("auth:reset_token:{$resetToken}");

        if ($email === null) {
            throw new AuthException('Invalid or expired reset token. Please request a new one.');
        }

        $userModel = config('auth.providers.users.model', \App\Models\User::class);
        $user      = $userModel::where('email', (string) $email)->firstOrFail();

        $user->update(['password' => Hash::make($newPassword)]);

        $this->tokenService->revokeAll($user);
        $this->sessionService->deleteAll($user);

        PasswordChanged::dispatch($user);
    }

    public function changePassword(User $user, string $currentPassword, string $newPassword, bool $logoutAll, Request $request): void
    {
        if (! Hash::check($currentPassword, (string) $user->password)) {
            throw new AuthException('Current password is incorrect.');
        }

        $user->update(['password' => Hash::make($newPassword)]);

        if ($logoutAll) {
            $currentTokenId = $user->currentAccessToken()?->id;

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
