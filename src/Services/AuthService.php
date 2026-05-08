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
use Joe404\LaravelAuth\Models\AuthSessionExtended;

class AuthService
{
    public function __construct(
        private readonly OtpService $otpService,
        private readonly TokenService $tokenService,
        private readonly SessionService $sessionService,
        private readonly LockoutService $lockoutService,
    ) {}

    public function initiateRegistration(string $email, string $password): array
    {
        $email = strtolower(trim($email));

        /** @var class-string<User> $userModel */
        $userModel = config('auth.providers.users.model', \App\Models\User::class);

        if ($userModel::where('email', $email)->exists()) {
            throw new \DomainException('This email is already registered.');
        }

        Cache::put(
            "auth:pending:{$email}",
            $password,
            now()->addMinutes((int) config('auth_system.password.pending_ttl_minutes', 60)),
        );

        $tempToken = Str::uuid()->toString();
        $method    = (string) config('auth_system.verification.method', 'both');

        if ($method === 'otp' || $method === 'both') {
            $this->otpService->sendOtp($email, 'email_verify', $tempToken);
        }

        if ($method === 'magic_link' || $method === 'both') {
            $this->otpService->sendMagicLink($email, 'magic_link_verify', $tempToken);
        }

        return [
            'temp_token' => $tempToken,
            'method'     => $method,
            'expires_in' => (int) config('auth_system.verification.otp_expiry', 10),
        ];
    }

    public function completeRegistrationWithOtp(string $email, string $code): array
    {
        $otpRecord = $this->otpService->validateOtp($email, $code, 'email_verify');

        return $this->finalizeRegistration($otpRecord->email, $otpRecord->temp_token ?? '');
    }

    public function completeRegistrationWithMagicLink(string $token): array
    {
        $otpRecord = $this->otpService->validateMagicLink($token, 'magic_link_verify');

        return $this->finalizeRegistration($otpRecord->email, $otpRecord->temp_token ?? '');
    }

    private function finalizeRegistration(string $email, string $tempToken): array
    {
        $plainPassword = Cache::pull("auth:pending:{$email}");

        if ($plainPassword === null) {
            throw new \RuntimeException('Registration session expired. Please start again.');
        }

        /** @var class-string<User> $userModel */
        $userModel = config('auth.providers.users.model', \App\Models\User::class);

        $name = (string) Str::before($email, '@');

        // Detect whether the host model has the 'hashed' cast on password.
        // If it does, pass the plain value so the cast hashes it once.
        // If it doesn't, hash it ourselves so it is never stored as plain text.
        $userInstance  = new $userModel;
        $castType      = method_exists($userInstance, 'getCasts') ? ($userInstance->getCasts()['password'] ?? null) : null;
        $passwordValue = ($castType === 'hashed') ? $plainPassword : Hash::make($plainPassword);

        /** @var User $user */
        $user = $userModel::create([
            'name'     => $name,
            'email'    => $email,
            'password' => $passwordValue,
        ]);

        // email_verified_at may not be in the model's fillable list, so set it directly.
        $user->email_verified_at = now();
        $user->save();

        $defaultRole = (string) config('auth_system.roles.default_role', 'user');

        if (method_exists($user, 'assignRole')) {
            $user->assignRole($defaultRole);
        }

        $tokenData = [];
        $mode      = (string) config('auth_system.mode', 'both');

        if ($mode !== 'web') {
            $tokenData = $this->tokenService->issue($user);
        }

        EmailVerified::dispatch($user, $tempToken, $tokenData['plain_text_token'] ?? null);

        return [
            'user'       => $user,
            'token'      => $tokenData['plain_text_token'] ?? null,
            'temp_token' => $tempToken,
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

        $isNewDevice = $this->isNewDevice($user, $request);

        $user->update(['last_login_at' => now()]);

        UserLoggedIn::dispatch($user, $request);

        if ($this->wantsToken($request)) {
            $tokenData = $this->tokenService->issue($user);

            $sanctumTokenId = $tokenData['token']->id;
            $this->sessionService->create($user, $request, $sanctumTokenId);

            if ($isNewDevice) {
                $this->dispatchSuspiciousLogin($user, $request);
            }

            return [
                'user'  => $user->toArray(),
                'token' => $tokenData['plain_text_token'],
            ];
        }

        Auth::login($user);
        $request->session()->regenerate();

        $this->sessionService->create($user, $request, null);

        if ($isNewDevice) {
            $this->dispatchSuspiciousLogin($user, $request);
        }

        return [
            'user'  => $user->toArray(),
            'token' => null,
        ];
    }

    private function isNewDevice(User $user, Request $request): bool
    {
        if (! (bool) config('auth_system.security.notify_new_device_login', true)) {
            return false;
        }

        /** @var array<string, mixed> $fingerprint */
        $fingerprint = $request->get('_device', []);
        $browser     = $fingerprint['browser'] ?? null;
        $os          = $fingerprint['os'] ?? null;

        if ($browser === null && $os === null) {
            return false;
        }

        return ! AuthSessionExtended::where('user_id', $user->getKey())
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

    private function wantsToken(Request $request): bool
    {
        $mode = (string) config('auth_system.mode', 'both');

        return match ($mode) {
            'api'  => true,
            'web'  => false,
            default => $request->hasHeader('X-Client-Type')
                && strtolower($request->header('X-Client-Type', '')) === 'mobile'
                || $request->expectsJson(),
        };
    }

    public function logout(Request $request): void
    {
        if ($request->bearerToken() !== null) {
            $tokenId = $request->user()?->currentAccessToken()?->id;

            if ($tokenId !== null) {
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

    public function logoutAll(User $user, ?int $exceptSessionId = null): void
    {
        $this->sessionService->deleteAll($user, $exceptSessionId);
        $this->tokenService->revokeAll($user);
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

        // Silently return if email not found — prevents enumeration
        if ($user === null) {
            return;
        }

        $method    = (string) config('auth_system.password_reset.method', 'both');
        $tempToken = Str::uuid()->toString();

        if ($method === 'otp' || $method === 'both') {
            $this->otpService->sendOtp($email, 'password_reset', $tempToken);
        }
        if ($method === 'magic_link' || $method === 'both') {
            $this->otpService->sendMagicLink($email, 'magic_link_reset', $tempToken);
        }
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
