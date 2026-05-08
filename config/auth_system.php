<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Mode
    |--------------------------------------------------------------------------
    | Supported: "api", "web", "both"
    | api  → only Bearer token (Sanctum)
    | web  → only session-based (cookie)
    | both → determine at runtime via X-Client-Type header or expectsJson()
    */
    'mode' => env('AUTH_MODE', 'both'),

    /*
    |--------------------------------------------------------------------------
    | Verification
    |--------------------------------------------------------------------------
    | method: "otp", "magic_link", "both"
    | otp_length: digit count for OTP codes
    | otp_expiry: minutes before OTP expires
    | magic_expiry: minutes before magic link expires
    */
    'verification' => [
        'method'       => env('AUTH_VERIFICATION_METHOD', 'both'),
        'otp_length'   => (int) env('AUTH_OTP_LENGTH', 6),
        'otp_expiry'   => (int) env('AUTH_OTP_EXPIRY', 10),
        'magic_expiry' => (int) env('AUTH_MAGIC_EXPIRY', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Token (Sanctum)
    |--------------------------------------------------------------------------
    */
    'token' => [
        'expiration_minutes' => (int) env('AUTH_TOKEN_EXPIRY', 10080), // 7 days
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limits
    |--------------------------------------------------------------------------
    | Format: "max_attempts:decay_minutes"
    */
    'rate_limits' => [
        'register'       => env('AUTH_RATE_REGISTER', '5:1'),
        'login'          => env('AUTH_RATE_LOGIN', '5:1'),
        'otp_send'       => env('AUTH_RATE_OTP_SEND', '3:1'),
        'password_reset' => env('AUTH_RATE_PASSWORD_RESET', '3:1'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Roles
    |--------------------------------------------------------------------------
    */
    'roles' => [
        'default_role' => env('AUTH_DEFAULT_ROLE', 'user'),
        'seeded_roles'  => ['super-admin', 'admin', 'user'],
    ],

    /*
    |--------------------------------------------------------------------------
    | OTP Channels / Drivers
    |--------------------------------------------------------------------------
    | driver: "email" | custom FQCN implementing OtpChannelContract
    */
    'otp_channel' => [
        'driver' => env('AUTH_OTP_CHANNEL', 'email'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Password Policy
    |--------------------------------------------------------------------------
    */
    'password' => [
        'min_length'          => (int) env('AUTH_PASSWORD_MIN', 8),
        'require_uppercase'   => (bool) env('AUTH_PASSWORD_UPPERCASE', false),
        'require_number'      => (bool) env('AUTH_PASSWORD_NUMBER', false),
        'require_special'     => (bool) env('AUTH_PASSWORD_SPECIAL', false),
        'pending_ttl_minutes' => (int) env('AUTH_PENDING_TTL', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Social / OAuth
    |--------------------------------------------------------------------------
    */
    'social' => [
        'google' => [
            'enabled' => (bool) env('AUTH_GOOGLE_ENABLED', false),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Reverb (WebSocket broadcasting)
    |--------------------------------------------------------------------------
    */
    'reverb' => [
        'enabled' => (bool) env('AUTH_REVERB_ENABLED', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Session / Security
    |--------------------------------------------------------------------------
    */
    'require_email_verification' => (bool) env('AUTH_REQUIRE_VERIFICATION', true),

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    */
    'queue' => [
        'connection' => env('AUTH_QUEUE_CONNECTION', null),
        'name'       => env('AUTH_QUEUE_NAME', 'auth-maintenance'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Response Formatter
    |--------------------------------------------------------------------------
    | Set to a FQCN implementing ResponseFormatterContract to override default.
    */
    'response' => [
        'formatter' => env('AUTH_RESPONSE_FORMATTER', null),
    ],

    /*
    |--------------------------------------------------------------------------
    | Security
    |--------------------------------------------------------------------------
    | notify_new_device_login: send email when user logs in from an unrecognised device
    | lockout.enabled:         enable account-level lockout after repeated failures
    | lockout.max_attempts:    failures before lockout is triggered
    | lockout.decay_minutes:   how long the lockout lasts
    */
    'security' => [
        'notify_new_device_login' => (bool) env('AUTH_NOTIFY_NEW_DEVICE', true),
        'lockout' => [
            'enabled'       => (bool) env('AUTH_LOCKOUT_ENABLED', true),
            'max_attempts'  => (int) env('AUTH_LOCKOUT_MAX', 10),
            'decay_minutes' => (int) env('AUTH_LOCKOUT_DECAY', 15),
        ],
    ],

];
