<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Joe404\LaravelAuth\Http\Controllers\AccountController;
use Joe404\LaravelAuth\Http\Controllers\Admin\ReferralController as AdminReferralController;
use Joe404\LaravelAuth\Http\Controllers\Admin\UserAuditController;
use Joe404\LaravelAuth\Http\Controllers\Admin\UserStatusController;
use Joe404\LaravelAuth\Http\Controllers\ApiTokenController;
use Joe404\LaravelAuth\Http\Controllers\LoginController;
use Joe404\LaravelAuth\Http\Controllers\LogoutController;
use Joe404\LaravelAuth\Http\Controllers\TokenRefreshController;
use Joe404\LaravelAuth\Http\Controllers\PasswordChangeController;
use Joe404\LaravelAuth\Http\Controllers\PasswordResetController;
use Joe404\LaravelAuth\Http\Controllers\ReferralController;
use Joe404\LaravelAuth\Http\Controllers\RegisterController;
use Joe404\LaravelAuth\Http\Controllers\SessionController;
use Joe404\LaravelAuth\Http\Controllers\UserDeviceController;
use Joe404\LaravelAuth\Http\Controllers\EmailVerificationController;
use Joe404\LaravelAuth\Http\Controllers\SocialAuthController;
use Joe404\LaravelAuth\Http\Controllers\PasswordConfirmController;
use Joe404\LaravelAuth\Http\Controllers\PhoneVerificationController;
use Joe404\LaravelAuth\Http\Controllers\TrustedDeviceController;
use Joe404\LaravelAuth\Http\Controllers\TwoFactorChallengeController;
use Joe404\LaravelAuth\Http\Controllers\TwoFactorController;

/*
|--------------------------------------------------------------------------
| M1 Auth Routes
|--------------------------------------------------------------------------
| Controllers not yet implemented (M2–M5) are listed as comments below.
| Only M1 controllers are registered; future routes will be added in their
| respective milestones.
*/

// Public routes
Route::middleware(['auth.device', 'throttle:api'])->group(function (): void {

    // Registration (M1)
    Route::post('register', [RegisterController::class, 'initiate'])
        ->middleware('auth.ratelimit:register');

    Route::post('register/verify-otp', [RegisterController::class, 'verifyOtp'])
        ->middleware('auth.ratelimit:otp_verify');

    Route::get('register/verify-magic/{token}', [RegisterController::class, 'verifyMagic'])
        ->name('auth.register.verify.magic');

    Route::post('register/complete', [RegisterController::class, 'complete']);

    // Login (M1)
    Route::post('login', [LoginController::class, 'login'])
        ->middleware('auth.ratelimit:login');

    // Token refresh — exchange a refresh_token for a new access + refresh token pair
    Route::post('token/refresh', [TokenRefreshController::class, 'refresh'])
        ->middleware('auth.ratelimit:login');

    // v2.6: Public 2FA challenge endpoints — accessed after login returns
    // requires_2fa: true. No auth guard yet; the challenge_token IS the proof.
    Route::post('2fa/challenge', [TwoFactorChallengeController::class, 'verify'])
        ->middleware('auth.ratelimit:otp_verify');
    Route::post('2fa/challenge/switch', [TwoFactorChallengeController::class, 'switch']);
    Route::post('2fa/challenge/resend', [TwoFactorChallengeController::class, 'resend'])
        ->middleware('auth.ratelimit:otp_send');

    // M6: Email re-verification (resend OTP / magic link)
    Route::post('email/resend-verification', [EmailVerificationController::class, 'resend'])
        ->middleware('auth.ratelimit:otp_send');

    // Force-destroy an orphaned session cookie without requiring auth. Meant
    // for SPAs to call after `/auth/me` returns 401 at app boot, so the next
    // request to the API does not carry a stale session cookie that points
    // at a no-longer-existing user.
    Route::post('session/clear', [LogoutController::class, 'clearOrphanSession']);

    // M4: Password reset
    Route::post('password/forgot', [PasswordResetController::class, 'forgot'])
        ->middleware('auth.ratelimit:password_reset');
    Route::post('password/reset/verify-otp', [PasswordResetController::class, 'verifyOtp'])
        ->middleware('auth.ratelimit:otp_verify');
    Route::get('password/reset/magic/{token}', [PasswordResetController::class, 'magicRedirect'])
        ->name('auth.password.reset.magic');
    Route::post('password/reset/confirm', [PasswordResetController::class, 'confirm'])
        ->middleware('auth.ratelimit:otp_verify');

    // M5: Social auth (Google OAuth)
    Route::get('social/google/redirect', [SocialAuthController::class, 'redirect']);
    Route::get('social/google/callback', [SocialAuthController::class, 'callback']);

    // v2.6: Finalize a brand-new social sign-up by submitting the host's
    // required registration fields. Gated by social.profile_completion.enabled
    // (controller returns 404 when disabled). Rate-limited like registration.
    Route::post('social/complete', [SocialAuthController::class, 'complete'])
        ->middleware('auth.ratelimit:register');

    // Click-through confirmation when a social provider's email matches an
    // existing local account but no link record exists yet. Signed URL ensures
    // the link cannot be tampered with.
    Route::get('social/{provider}/link/confirm/{token}', [SocialAuthController::class, 'confirmLink'])
        ->name('auth.social.link.confirm');
});

// Authenticated routes
Route::middleware(['auth:sanctum', 'auth.no-refresh', 'auth.verified', 'auth.device'])->group(function (): void {

    // M1: Core authenticated endpoints
    Route::get('me', [LoginController::class, 'me']);
    Route::post('logout', [LogoutController::class, 'logout']);

    // M2: Token/session management
    Route::post('logout/all', [LogoutController::class, 'logoutAll']);
    Route::get('sessions', [SessionController::class, 'index']);
    Route::delete('sessions/{id}', [SessionController::class, 'destroy']);

    // Permanent device history (survives logout) — security audit for
    // the user ("who has ever logged into my account?") and the backing
    // store for referral abuse detection.
    Route::get('devices', [UserDeviceController::class, 'index']);
    Route::delete('devices/{id}', [UserDeviceController::class, 'destroy']);

    // M4: Password change
    Route::post('password/change', [PasswordChangeController::class, 'change']);

    // v2.6: Phone verification — send + verify codes for the authenticated user.
    // Feature-gated via the phone.enabled config flag; routes are present at
    // boot but controllers return 404 when disabled.
    Route::post('phone/send-otp', [PhoneVerificationController::class, 'send'])
        ->middleware('auth.ratelimit:otp_send');
    Route::post('phone/verify', [PhoneVerificationController::class, 'verify'])
        ->middleware('auth.ratelimit:otp_verify');

    // v2.6: Two-Factor management (authenticated user). Feature-gated by
    // auth_system.two_factor.enabled — controller returns 404 when disabled.
    Route::prefix('2fa')->group(function (): void {
        Route::get('methods', [TwoFactorController::class, 'index']);
        Route::post('enroll/{method}/start', [TwoFactorController::class, 'startEnroll'])
            ->middleware('auth.ratelimit:otp_send')
            ->whereIn('method', ['totp', 'email', 'sms']);
        Route::post('enroll/{method}/verify', [TwoFactorController::class, 'verifyEnroll'])
            ->middleware('auth.ratelimit:otp_verify')
            ->whereIn('method', ['totp', 'email', 'sms']);
        Route::delete('methods/{id}', [TwoFactorController::class, 'destroy'])
            ->whereNumber('id');
        Route::post('methods/{id}/default', [TwoFactorController::class, 'setDefault'])
            ->whereNumber('id');
        Route::get('backup-codes', [TwoFactorController::class, 'backupCodesSummary']);
        Route::post('backup-codes/regenerate', [TwoFactorController::class, 'regenerateBackupCodes']);
    });

    // v2.6: Password "sudo" confirmation — issues a short-lived confirm
    // window the Require2FA middleware accepts in lieu of a 2FA challenge
    // when middleware_behavior=password_confirm and the user has no 2FA.
    Route::post('password/confirm', [PasswordConfirmController::class, 'confirm']);

    // v2.6: Trusted devices. All revocation routes go through Require2FA
    // middleware so users with 2FA enrolled must step up before nuking a
    // device. Users without 2FA fall through password_confirm.
    Route::prefix('trusted-devices')->group(function (): void {
        Route::get('/', [TrustedDeviceController::class, 'index']);
        Route::delete('/', [TrustedDeviceController::class, 'destroyAll'])
            ->middleware('auth.2fa');
        Route::delete('{id}', [TrustedDeviceController::class, 'destroy'])
            ->middleware('auth.2fa')
            ->whereNumber('id');
    });

    // v2.4: Self-service account deletion. Grace-period auto-restore is handled
    // by the login flow — no explicit restore endpoint needed.
    Route::delete('account', [AccountController::class, 'destroy']);

    // v2.4: Self-service deactivation (Instagram-style pause). Login
    // auto-reactivates the user — no separate reactivate endpoint needed.
    Route::post('account/deactivate', [AccountController::class, 'deactivate']);

    // M3: API token management (gated at request time by `auth.feature` middleware
    // so `php artisan route:cache` is safe regardless of the feature flag's
    // value at cache time).
    Route::middleware('auth.feature:api_tokens')->group(function (): void {
        Route::get('api-tokens', [ApiTokenController::class, 'index']);
        Route::post('api-tokens', [ApiTokenController::class, 'store']);
        Route::delete('api-tokens/{id}', [ApiTokenController::class, 'destroy']);
    });

    // Referral codes — gated on auth_system.referral_code.enabled. The
    // redeem endpoint is the fallback path for users who forgot to enter
    // a code during registration; index + stats are the dashboard reads.
    Route::middleware('auth.feature:referral_code')->group(function (): void {
        Route::post('referrals/redeem', [ReferralController::class, 'redeem']);
        Route::get('referrals', [ReferralController::class, 'index']);
        Route::get('referrals/stats', [ReferralController::class, 'stats']);
    });
});

// Admin routes — same gating pattern.
Route::middleware([
    'auth:sanctum',
    'auth.no-refresh',
    'auth.verified',
    'role:super-admin|admin',
    'auth.feature:api_tokens',
])->prefix('admin')->group(function (): void {
    Route::get('api-tokens', [ApiTokenController::class, 'adminIndex']);
    Route::post('api-tokens', [ApiTokenController::class, 'adminStore']);
    Route::patch('api-tokens/{id}', [ApiTokenController::class, 'adminUpdate']);
    Route::delete('api-tokens/{id}', [ApiTokenController::class, 'adminDestroy']);
});

// v2.4: Admin account status management. Gated by the role declared in
// config('auth_system.account.status.admin_ability') so hosts can switch
// to a permission name (e.g. "users.manage-status") without editing the
// package.
Route::middleware([
    'auth:sanctum',
    'auth.no-refresh',
    'auth.verified',
    'role:' . (string) config('auth_system.account.status.admin_ability', 'super-admin|admin'),
])->prefix('admin')->group(function (): void {
    Route::get('users/{id}/status', [UserStatusController::class, 'show']);
    Route::post('users/{id}/status', [UserStatusController::class, 'update']);

    // v2.4 audit log — paginated status history + free-form admin notes.
    Route::get('users/{id}/status/history', [UserAuditController::class, 'history']);
    Route::post('users/{id}/notes', [UserAuditController::class, 'addNote']);
});

// Admin referral management — separate group so it can keep the same
// admin_ability gating as the user-status admin routes.
Route::middleware([
    'auth:sanctum',
    'auth.no-refresh',
    'auth.verified',
    'role:' . (string) config('auth_system.account.status.admin_ability', 'super-admin|admin'),
    'auth.feature:referral_code',
])->prefix('admin')->group(function (): void {
    Route::get('referrals', [AdminReferralController::class, 'index']);
    Route::patch('referrals/{id}', [AdminReferralController::class, 'update']);
});
