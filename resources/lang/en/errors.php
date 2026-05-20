<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| joe-404/laravel-auth — Error / Exception Messages (English)
|--------------------------------------------------------------------------
|
| These translate exception classes thrown by the package services. Each
| key matches an exception's "errorKey()". Add or override values in
| translated copies of this file (see messages.php for publish steps).
|
| The :placeholder syntax is the standard Laravel translation interpolation.
|
*/

return [
    // Registration
    'registration_session_expired' => 'Registration session expired. Please start again.',
    'completion_token_invalid'     => 'Invalid or expired completion token. Please verify your email again.',
    'email_already_registered'     => 'This email is already registered.',
    'magic_link_invalid'           => 'Invalid or expired verification link.',

    // Login
    'invalid_credentials' => 'Invalid credentials.',
    'account_inactive'    => 'This account has been deactivated.',
    'email_not_verified'  => 'Email address is not verified.',

    // OTP
    'otp_invalid' => 'The OTP code is invalid.',
    'otp_expired' => 'The OTP code has expired.',

    // Password reset / change
    'reset_token_invalid'      => 'Invalid or expired reset token. Please request a new one.',
    'current_password_invalid' => 'Current password is incorrect.',

    // Tokens
    'api_token_invalid_format'   => 'Invalid API token format.',
    'api_token_invalid_encoding' => 'Invalid API token encoding.',
    'api_token_revoked'          => 'API token has been revoked.',
    'api_token_expired'          => 'API token has expired.',
    'refresh_token_invalid'      => 'Invalid refresh token.',
    'refresh_token_revoked'      => 'Refresh token has been revoked. Please log in again.',
    'refresh_token_reused'       => 'Refresh token reuse detected. All sessions for this family have been revoked.',
    'refresh_token_expired'      => 'Refresh token has expired. Please log in again.',

    // Sessions
    'session_not_found' => 'Session not found.',

    // Social
    'social_provider_disabled'        => ':provider authentication is not enabled.',
    'social_authentication_failed'    => 'Unable to authenticate with :provider. Please try again.',
    'social_email_unverified'         => 'The email associated with your :provider account is not verified.',
    'social_link_token_invalid'       => 'Invalid or expired confirmation link.',
    'social_user_not_found'           => 'User not found.',

    // Lockout / rate limiting
    'account_locked' => 'Account temporarily locked due to too many failed attempts. Try again in :seconds seconds.',

    // Generic
    'unauthenticated' => 'Unauthenticated.',

    // v2.4 — Account status / deletion
    'account_disabled'           => 'This account has been disabled. Please contact support.',
    'account_suspended'          => 'This account has been suspended. Please contact support.',
    'account_deletion_disabled'     => 'Account deletion is currently disabled.',
    'account_deactivation_disabled' => 'Account deactivation is currently disabled.',
    'account_status_invalid'        => 'The provided account status is not valid.',
    'account_password_mismatch'     => 'The provided password is incorrect.',

    // Referral codes
    'referral_code_not_found'      => 'Referral code not found.',
    'referral_self_referral'       => 'You cannot use your own referral code.',
    'referral_already_redeemed'    => 'You have already redeemed a referral code.',
    'referral_window_expired'      => 'Referral code can no longer be redeemed. The redemption window has passed.',
    'referral_blocked_same_device' => 'This referral code cannot be used from this device.',
    'referral_blocked_same_ip'     => 'This referral code cannot be used from this network.',
    'referral_blocked'             => 'This referral code cannot be redeemed.',
    'referral_status_invalid'      => 'The provided referral status is not valid.',
    'referral_not_found'           => 'Referral not found.',

    // Device history
    'device_not_found'             => 'Device not found.',
];
