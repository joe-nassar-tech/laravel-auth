<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Joe404\LaravelAuth\Http\Controllers\ApiTokenController;
use Joe404\LaravelAuth\Http\Controllers\LoginController;
use Joe404\LaravelAuth\Http\Controllers\LogoutController;
use Joe404\LaravelAuth\Http\Controllers\TokenRefreshController;
use Joe404\LaravelAuth\Http\Controllers\PasswordChangeController;
use Joe404\LaravelAuth\Http\Controllers\PasswordResetController;
use Joe404\LaravelAuth\Http\Controllers\RegisterController;
use Joe404\LaravelAuth\Http\Controllers\SessionController;
use Joe404\LaravelAuth\Http\Controllers\EmailVerificationController;
use Joe404\LaravelAuth\Http\Controllers\SocialAuthController;

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

    // M6: Email re-verification (resend OTP / magic link)
    Route::post('email/resend-verification', [EmailVerificationController::class, 'resend'])
        ->middleware('auth.ratelimit:otp_send');

    // M4: Password reset
    Route::post('password/forgot', [PasswordResetController::class, 'forgot'])
        ->middleware('auth.ratelimit:password_reset');
    Route::post('password/reset/otp', [PasswordResetController::class, 'resetWithOtp'])
        ->middleware('auth.ratelimit:otp_verify');
    Route::get('password/reset/magic/{token}', [PasswordResetController::class, 'magicRedirect'])
        ->name('auth.password.reset.magic');
    Route::post('password/reset/confirm', [PasswordResetController::class, 'confirm'])
        ->middleware('auth.ratelimit:otp_verify');

    // M5: Social auth (Google OAuth)
    Route::get('social/google/redirect', [SocialAuthController::class, 'redirect']);
    Route::get('social/google/callback', [SocialAuthController::class, 'callback']);

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

    // M4: Password change
    Route::post('password/change', [PasswordChangeController::class, 'change']);

    // M3: API token management (gated at request time by `auth.feature` middleware
    // so `php artisan route:cache` is safe regardless of the feature flag's
    // value at cache time).
    Route::middleware('auth.feature:api_tokens')->group(function (): void {
        Route::get('api-tokens', [ApiTokenController::class, 'index']);
        Route::post('api-tokens', [ApiTokenController::class, 'store']);
        Route::delete('api-tokens/{id}', [ApiTokenController::class, 'destroy']);
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
