<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Joe404\LaravelAuth\Http\Controllers\ApiTokenController;
use Joe404\LaravelAuth\Http\Controllers\LoginController;
use Joe404\LaravelAuth\Http\Controllers\LogoutController;
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

    Route::post('register/verify-otp', [RegisterController::class, 'verifyOtp']);

    Route::get('register/verify-magic/{token}', [RegisterController::class, 'verifyMagic'])
        ->name('auth.register.verify.magic');

    // Login (M1)
    Route::post('login', [LoginController::class, 'login'])
        ->middleware('auth.ratelimit:login');

    // M6: Email re-verification (resend OTP / magic link)
    Route::post('email/resend-verification', [EmailVerificationController::class, 'resend'])
        ->middleware('auth.ratelimit:otp_send');

    // M4: Password reset
    Route::post('password/forgot', [PasswordResetController::class, 'forgot'])
        ->middleware('auth.ratelimit:password_reset');
    Route::post('password/reset/otp', [PasswordResetController::class, 'resetWithOtp']);
    Route::get('password/reset/magic/{token}', [PasswordResetController::class, 'magicRedirect'])
        ->name('auth.password.reset.magic');
    Route::post('password/reset/confirm', [PasswordResetController::class, 'confirm']);

    // M5: Social auth (Google OAuth)
    Route::get('social/google/redirect', [SocialAuthController::class, 'redirect']);
    Route::get('social/google/callback', [SocialAuthController::class, 'callback']);
});

// Authenticated routes
Route::middleware(['auth:sanctum', 'auth.verified', 'auth.device'])->group(function (): void {

    // M1: Core authenticated endpoints
    Route::get('me', [LoginController::class, 'me']);
    Route::post('logout', [LogoutController::class, 'logout']);

    // M2: Token/session management
    Route::post('logout/all', [LogoutController::class, 'logoutAll']);
    Route::get('sessions', [SessionController::class, 'index']);
    Route::delete('sessions/{id}', [SessionController::class, 'destroy']);

    // M4: Password change
    Route::post('password/change', [PasswordChangeController::class, 'change']);

    // M3: API token management
    Route::middleware('auth.mode:api,both')->group(function (): void {
        Route::get('api-tokens', [ApiTokenController::class, 'index']);
        Route::post('api-tokens', [ApiTokenController::class, 'store']);
        Route::delete('api-tokens/{id}', [ApiTokenController::class, 'destroy']);
    });
});

// Admin routes
Route::middleware(['auth:sanctum', 'auth.verified', 'role:super-admin|admin'])->prefix('admin')->group(function (): void {
    Route::get('api-tokens', [ApiTokenController::class, 'adminIndex']);
    Route::post('api-tokens', [ApiTokenController::class, 'adminStore']);
    Route::patch('api-tokens/{id}', [ApiTokenController::class, 'adminUpdate']);
    Route::delete('api-tokens/{id}', [ApiTokenController::class, 'adminDestroy']);
});
