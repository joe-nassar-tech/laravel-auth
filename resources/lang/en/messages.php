<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| joe-404/laravel-auth — Success Response Messages (English)
|--------------------------------------------------------------------------
|
| These are the default English strings the package controllers return on
| success. Translate them by publishing this file:
|
|     php artisan vendor:publish --tag=auth-lang
|
| Then create resources/lang/<locale>/vendor/auth_system/messages.php
| (Laravel < 9 layout) or lang/vendor/auth_system/<locale>/messages.php
| (Laravel 9+ default layout) and translate the values.
|
| The active locale is whatever Laravel's app()->getLocale() returns at
| request time (typically set by your app's locale middleware).
|
*/

return [
    // Registration
    'register_initiated'  => 'Verification sent. Please check your email.',
    'register_verified'   => 'Email verified. Please set your password.',
    'register_complete'   => 'Registration complete.',
    'verification_resent' => 'If your email is pending verification, new instructions have been sent.',

    // Authentication
    'login_success'      => 'Logged in successfully.',
    'me_retrieved'       => 'User retrieved.',
    'logout_success'     => 'Logged out successfully.',
    'logout_all_success' => 'Logged out of all sessions.',

    // Password
    'password_reset_sent'    => 'If that email is registered, you will receive reset instructions shortly.',
    'password_reset_otp_ok'  => 'OTP verified. Submit your new password using the reset_token.',
    'password_reset_link_ok' => 'Link validated. Submit your new password using the reset_token.',
    'password_reset_success' => 'Password reset successfully. You are now logged in.',
    'password_changed'       => 'Password changed successfully.',

    // Sessions
    'sessions_retrieved' => 'Sessions retrieved.',
    'session_terminated' => 'Session terminated.',

    // API tokens
    'api_tokens_retrieved' => 'API tokens retrieved.',
    'api_token_created'    => 'API token created.',
    'api_token_updated'    => 'API token updated.',
    'api_token_revoked'    => 'API token revoked.',

    // v2.4 — Account lifecycle
    'account_deleted'        => 'Account scheduled for deletion.',
    'account_restored'       => 'Account restored.',
    'account_status_updated' => 'Account status updated.',
    'account_deactivated'    => 'Account deactivated. Log in any time to reactivate.',
    'account_reactivated'    => 'Welcome back — your account has been reactivated.',

    // Referral codes
    'referral_redeemed'        => 'Referral code submitted.',
    'referrals_retrieved'      => 'Referrals retrieved.',
    'referral_stats_retrieved' => 'Referral stats retrieved.',
    'referral_status_updated'  => 'Referral status updated.',

    // Device history
    'devices_retrieved'        => 'Devices retrieved.',
    'device_forgotten'         => 'Device forgotten.',
];
