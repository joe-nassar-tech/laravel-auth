<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Mode
    |--------------------------------------------------------------------------
    |
    | This controls how users are authenticated after a successful login.
    |
    | "api"  → Always return a Bearer token. Best for pure API / mobile apps.
    |
    | "web"  → Always use Laravel session + cookie. Best for server-rendered
    |          apps or SPAs that want cookie-based auth.
    |
    | "both" → Smart detection. Decide at runtime based on the request:
    |          - If the request has "X-Client-Type: mobile" header → token
    |          - If AUTH_SPA_TOKEN=true and no X-Client-Type → token
    |          - Otherwise → session/cookie
    |
    | Example:
    |   AUTH_MODE=api        → mobile app backend
    |   AUTH_MODE=web        → traditional web app or SPA with cookies
    |   AUTH_MODE=both       → serves both mobile and browser from one backend
    */
    'mode' => env('AUTH_MODE', 'both'),

    /*
    |--------------------------------------------------------------------------
    | SPA Token Mode
    |--------------------------------------------------------------------------
    |
    | Only applies when AUTH_MODE=both.
    |
    | By default, SPA (browser) requests receive a session cookie, not a token.
    | Set this to true if your SPA prefers to use a Bearer token instead.
    |
    | true  → SPA gets a Bearer token (same as mobile)
    | false → SPA gets a session cookie (default, recommended for browsers)
    |
    | Example:
    |   AUTH_SPA_TOKEN=false   → browser gets a cookie (default)
    |   AUTH_SPA_TOKEN=true    → browser gets a token instead
    */
    'spa_token' => (bool) env('AUTH_SPA_TOKEN', false),

    /*
    |--------------------------------------------------------------------------
    | Route registration
    |--------------------------------------------------------------------------
    |
    | Controls how (and whether) the package mounts its HTTP routes.
    |
    | register:
    |   true  → Auto-mount under the configured prefix + middleware (default).
    |   false → Don't mount automatically. The host app must require the route
    |           file itself, e.g.:
    |
    |               Route::prefix('api/v1/auth')->middleware([...])->group(
    |                   base_path('vendor/joe-404/laravel-auth/routes/auth.php')
    |               );
    |
    |           Useful when you want the package endpoints inside a versioned
    |           API group with project-specific middleware ordering.
    |
    | prefix:
    |   The URL prefix the routes are mounted under when register=true.
    |   Default 'auth' → /auth/login, /auth/register, etc. Set to 'api/v1/auth'
    |   for versioned API hosts that prefer one global group instead of the
    |   manual mount above.
    |
    | middleware:
    |   When null (default), the package picks a middleware stack based on
    |   `mode` (api → ['api']; web/both → cookie+session+ConditionalCsrf+api).
    |   Set to an array to override completely.
    |
    | Example (Creator-Platform style — versioned auto-mount):
    |   AUTH_ROUTES_PREFIX=api/v1/auth
    |
    | Example (full manual control):
    |   AUTH_ROUTES_REGISTER=false
    */
    'routes' => [
        'register'   => (bool) env('AUTH_ROUTES_REGISTER', true),
        'prefix'     => env('AUTH_ROUTES_PREFIX', 'auth'),
        'middleware' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Registration
    |--------------------------------------------------------------------------
    |
    | These options let you add extra fields to the registration form without
    | touching the library code.
    |
    | --- Option A: extra_fields_rules (simple) ---
    | Add extra validation rules directly here. The library will validate them
    | automatically and pass the values to User::create() on registration.
    |
    | The keys are field names, values are standard Laravel validation rules.
    | Make sure the field names are in your User model's $fillable array.
    |
    | Example:
    |   'extra_fields_rules' => [
    |       'birthday' => 'required|date',
    |       'phone'    => 'nullable|string|max:20',
    |       'country'  => 'required|string|size:2',
    |   ],
    |
    | --- Option B: request_class (full control) ---
    | Point to your own FormRequest class that extends RegisterRequest.
    | Use this when you need custom error messages, complex conditional rules,
    | or any logic that goes beyond simple rule strings.
    |
    | Example:
    |   'request_class' => \App\Http\Requests\MyRegisterRequest::class,
    |
    | NOTE: Option B takes priority over Option A if both are set.
    */
    'registration' => [
        'extra_fields_rules' => [
            // 'birthday' => 'required|date',
            // 'phone'    => 'nullable|string|max:20',
        ],

        /*
        | Custom validation messages for extra fields. Standard Laravel
        | "field.rule" → message format. Lets you override Laravel's defaults
        | per field/rule combo without writing a custom request class.
        |
        | Example:
        |   'extra_fields_messages' => [
        |       'username.unique'         => 'This username is already taken.',
        |       'username.regex'          => 'Letters, numbers, and underscores only.',
        |       'date_of_birth.before'    => 'You must be 18+ to register.',
        |   ],
        */
        'extra_fields_messages' => [
            // 'username.unique' => 'This username is already taken.',
        ],

        /*
        | Field transformers — derive new fields from validated input after
        | validation passes, without writing a custom controller. Each entry
        | maps a target field name → a class implementing
        | ExtraFieldTransformerContract::transform(array $validated): mixed.
        |
        | Useful for normalization (lowercase, trim) or derivation
        | (username_normalized = strtolower(username)).
        |
        | Example:
        |   'extra_fields_transformers' => [
        |       'username_normalized' => \App\Transformers\UsernameNormalizer::class,
        |   ],
        */
        'extra_fields_transformers' => [
            // 'username_normalized' => \App\Transformers\UsernameNormalizer::class,
        ],

        'request_class' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Referral Codes
    |--------------------------------------------------------------------------
    |
    | When enabled, the package generates a unique referral code for every
    | new user during finalizeRegistration() and writes it to the configured
    | column. The host app gets a working referral system with zero code.
    |
    | enabled:
    |   false → no code is generated (default; unchanged from older versions)
    |   true  → generate and store a unique code per new user
    |
    | column:
    |   The users-table column that stores the code. Must exist in your
    |   schema and be in your User model's $fillable.
    |
    | length / uppercase:
    |   Code shape. Default: 10 chars, uppercased (e.g. "A8F3K2P9LM").
    |
    | generator:
    |   FQCN of a class implementing ReferralCodeGeneratorContract. When set,
    |   the package uses your generator instead of the built-in random one.
    |   Useful when you need deterministic codes, vanity prefixes, or
    |   integration with an existing system.
    |
    | Example:
    |   AUTH_REFERRAL_CODE_ENABLED=true
    |   AUTH_REFERRAL_CODE_LENGTH=8
    */
    'referral_code' => [
        'enabled'   => (bool) env('AUTH_REFERRAL_CODE_ENABLED', false),
        'column'    => env('AUTH_REFERRAL_CODE_COLUMN', 'referral_code'),
        'length'    => (int) env('AUTH_REFERRAL_CODE_LENGTH', 10),
        'uppercase' => (bool) env('AUTH_REFERRAL_CODE_UPPERCASE', true),
        'generator' => env('AUTH_REFERRAL_CODE_GENERATOR', null),
    ],

    /*
    |--------------------------------------------------------------------------
    | Response Messages
    |--------------------------------------------------------------------------
    |
    | Override any of the package's hardcoded English response messages.
    | Set a value to override; leave as null to use the built-in default.
    |
    | Useful for localization (point the values at translation strings),
    | rebranding, or matching your app's tone of voice.
    |
    | Example:
    |   'messages' => [
    |       'register_initiated' => trans('auth.register_initiated'),
    |       'login_success'      => 'Welcome back!',
    |   ],
    */
    /*
    |--------------------------------------------------------------------------
    | Error / Exception Messages
    |--------------------------------------------------------------------------
    |
    | Static per-key overrides for error messages thrown by package services.
    | Resolution order at request time:
    |
    |   1. config('auth_system.errors.<key>')      → wins if set to non-empty
    |   2. trans('auth_system::errors.<key>')      → per-locale, publish via
    |                                                vendor:publish --tag=auth-lang
    |   3. The exception's built-in English message
    |
    | Keys mirror the file resources/lang/en/errors.php. Set a value here to
    | force a single global override regardless of locale; leave null to keep
    | the translation pipeline active.
    |
    | Some keys accept placeholders (interpolated as :name):
    |   account_locked              → :seconds
    |   social_provider_disabled    → :provider
    |   social_authentication_failed → :provider
    |   social_email_unverified     → :provider
    */
    'errors' => [
        'invalid_credentials'          => null,
        'account_inactive'             => null,
        'email_not_verified'           => null,
        'otp_invalid'                  => null,
        'otp_expired'                  => null,
        'completion_token_invalid'     => null,
        'registration_session_expired' => null,
        'email_already_registered'     => null,
        'reset_token_invalid'          => null,
        'current_password_invalid'     => null,
        'refresh_token_invalid'        => null,
        'refresh_token_revoked'        => null,
        'refresh_token_reused'         => null,
        'refresh_token_expired'        => null,
        'api_token_invalid_format'     => null,
        'api_token_invalid_encoding'   => null,
        'api_token_revoked'            => null,
        'api_token_expired'            => null,
        'social_provider_disabled'     => null,
        'social_authentication_failed' => null,
        'social_email_unverified'      => null,
        'social_link_token_invalid'    => null,
        'social_user_not_found'        => null,
        'session_not_found'            => null,
        'account_locked'               => null,
        'unauthenticated'              => null,
        // v2.4 account status / deletion
        'account_disabled'             => null,
        'account_suspended'            => null,
        'account_deletion_disabled'    => null,
        'account_deactivation_disabled' => null,
        'account_status_invalid'       => null,
        'account_password_mismatch'    => null,
    ],

    'messages' => [
        'register_initiated'     => null,
        'register_verified'      => null,
        'register_complete'      => null,
        'verification_resent'    => null,
        'login_success'          => null,
        'me_retrieved'           => null,
        'logout_success'         => null,
        'logout_all_success'     => null,
        'password_reset_sent'    => null,
        'password_reset_otp_ok'  => null,
        'password_reset_link_ok' => null,
        'password_reset_success' => null,
        'password_changed'       => null,
        'sessions_retrieved'     => null,
        'session_terminated'     => null,
        'api_tokens_retrieved'   => null,
        'api_token_created'      => null,
        'api_token_updated'      => null,
        'api_token_revoked'      => null,
        // v2.4 account lifecycle
        'account_deleted'        => null,
        'account_restored'       => null,
        'account_status_updated' => null,
        'account_deactivated'    => null,
        'account_reactivated'    => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Email Verification
    |--------------------------------------------------------------------------
    |
    | Controls how users verify their email address after registration,
    | and how password reset codes/links are sent.
    |
    | method:
    |   "otp"        → Send a numeric code (e.g. 482910). User types it in.
    |   "magic_link" → Send a clickable link. User clicks to verify.
    |   "both"       → Send ONE email that contains BOTH the code and the link.
    |                  User can use whichever is easier for them.
    |
    | otp_length:
    |   How many digits the OTP code has. Between 4 and 8.
    |   Example: 6 → "482910"
    |
    | otp_expiry:
    |   How many minutes the OTP code is valid for.
    |   After this time, the user must request a new code.
    |
    | magic_expiry:
    |   How many minutes the magic link is valid for.
    |   After this time, the link stops working.
    |
    | magic_link_target:
    |   "backend"  → The link points to your Laravel API (default).
    |                Clicking it hits GET /auth/register/verify-magic/{token}.
    |   "frontend" → The link points to your frontend app (SPA, mobile deep link).
    |                Your frontend extracts ?token= and calls the backend itself.
    |                Requires frontend_verify_url and frontend_reset_url to be set.
    |
    | frontend_verify_url:
    |   The URL on your frontend where email verification magic links point.
    |   Example: https://myapp.com/verify-email
    |   The library adds ?token=xxx to this URL automatically.
    |
    | frontend_reset_url:
    |   The URL on your frontend where password reset magic links point.
    |   Example: https://myapp.com/reset-password
    |   The library adds ?token=xxx to this URL automatically.
    */
    'verification' => [
        'method'              => env('AUTH_VERIFICATION_METHOD', 'both'),
        'otp_length'          => (int) env('AUTH_OTP_LENGTH', 6),
        'otp_expiry'          => (int) env('AUTH_OTP_EXPIRY', 10),
        // Maximum number of incorrect OTP submissions before the active OTP
        // is invalidated. Defends against brute-forcing the 6-digit space.
        'otp_max_attempts'    => (int) env('AUTH_OTP_MAX_ATTEMPTS', 5),
        'magic_expiry'        => (int) env('AUTH_MAGIC_EXPIRY', 30),
        'magic_link_target'   => env('AUTH_MAGIC_LINK_TARGET', 'backend'),
        'frontend_verify_url' => env('AUTH_FRONTEND_VERIFY_URL', null),
        'frontend_reset_url'  => env('AUTH_FRONTEND_RESET_URL', null),
    ],

    /*
    |--------------------------------------------------------------------------
    | Password Reset
    |--------------------------------------------------------------------------
    |
    | Controls how password reset codes/links are delivered to the user.
    | When not set (null), the value from verification.method is used instead.
    |
    | method:
    |   null         → inherit from verification.method (default)
    |   "otp"        → send a numeric code only
    |   "magic_link" → send a clickable link only
    |   "both"       → send one email with both OTP and magic link
    |
    | Example — if your app uses magic_link for verification but you prefer OTP
    | codes for password resets (easier to type on a reset form):
    |   AUTH_PASSWORD_RESET_METHOD=otp
    */
    'password_reset' => [
        'method' => env('AUTH_PASSWORD_RESET_METHOD', null),
    ],

    /*
    |--------------------------------------------------------------------------
    | Token TTL (Time To Live) per Client Type
    |--------------------------------------------------------------------------
    |
    | Control how long access tokens and refresh tokens stay valid,
    | with different durations for each type of client.
    |
    | access_minutes:
    |   How long the Bearer access token works after login.
    |   Set to 0 for tokens that never expire (not recommended for security).
    |
    | refresh_minutes:
    |   How long the refresh token works. The user can use it to get a new
    |   access token without logging in again.
    |   Set to 0 for refresh tokens that never expire.
    |
    | --- mobile ---
    |   Used when login request has "X-Client-Type: mobile" header.
    |   Mobile apps usually need longer-lived tokens.
    |   Default: 7-day access token, 30-day refresh token.
    |
    | --- spa ---
    |   Used when AUTH_MODE=both and AUTH_SPA_TOKEN=true.
    |   Browser apps usually need shorter-lived tokens for security.
    |   Default: 24-hour access token, 7-day refresh token.
    |
    | --- api ---
    |   Used when AUTH_MODE=api (server-to-server / pure API clients).
    |   These are usually long-lived since no user is involved.
    |   Default: 365-day access token, refresh never expires (0).
    |
    | --- web ---
    |   Used when auth mode is web/session. No tokens are issued.
    |   session_minutes mirrors your SESSION_LIFETIME setting.
    |   Keep this in sync with SESSION_LIFETIME in your .env file.
    |
    | Example — short-lived SPA tokens for tighter security:
    |   AUTH_TOKEN_TTL_SPA=60       → 1 hour
    |   AUTH_REFRESH_TTL_SPA=1440   → 24 hours
    */
    'token_ttl' => [
        'mobile' => [
            'access_minutes'  => (int) env('AUTH_TOKEN_TTL_MOBILE', 10080),   // 7 days
            'refresh_minutes' => (int) env('AUTH_REFRESH_TTL_MOBILE', 43200), // 30 days
        ],
        'spa' => [
            'access_minutes'  => (int) env('AUTH_TOKEN_TTL_SPA', 1440),       // 24 hours
            'refresh_minutes' => (int) env('AUTH_REFRESH_TTL_SPA', 10080),    // 7 days
        ],
        'api' => [
            'access_minutes'  => (int) env('AUTH_TOKEN_TTL_API', 525600),     // 365 days
            'refresh_minutes' => (int) env('AUTH_REFRESH_TTL_API', 0),        // never expires
        ],
        'web' => [
            'session_minutes' => (int) env('AUTH_SESSION_TTL', 120),          // keep in sync with SESSION_LIFETIME
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    |
    | Protects auth endpoints from brute-force and spam attacks.
    | Rate limits apply per IP address AND per email address independently.
    | If either is over the limit, the request is blocked with HTTP 429.
    |
    | Format: "max_attempts:decay_minutes"
    | Example: "5:1" means 5 attempts per 1 minute window.
    |
    | register:       POST /auth/register
    | login:          POST /auth/login  (also used for POST /auth/token/refresh)
    | otp_send:       POST /auth/email/resend-verification
    | password_reset: POST /auth/password/forgot
    |
    | Example — stricter limits for production:
    |   AUTH_RATE_LOGIN=3:5          → only 3 login attempts per 5 minutes
    |   AUTH_RATE_PASSWORD_RESET=2:10 → only 2 reset requests per 10 minutes
    */
    'rate_limits' => [
        'register'       => env('AUTH_RATE_REGISTER', '5:1'),
        'login'          => env('AUTH_RATE_LOGIN', '5:1'),
        'otp_send'       => env('AUTH_RATE_OTP_SEND', '3:1'),
        'otp_verify'     => env('AUTH_RATE_OTP_VERIFY', '10:5'),
        'password_reset' => env('AUTH_RATE_PASSWORD_RESET', '3:1'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Password Policy
    |--------------------------------------------------------------------------
    |
    | Rules enforced when a user registers or changes their password.
    |
    | min_length:         Minimum number of characters. Default: 8.
    | require_uppercase:  Must contain at least one capital letter (A-Z).
    | require_number:     Must contain at least one number (0-9).
    | require_special:    Must contain at least one symbol (!@#$%...).
    |
    | pending_ttl_minutes:
    |   During registration, the user's email + extra fields are held in cache
    |   until they click the verification link. This is how many minutes that
    |   cache entry lives. If the user does not verify within this window they
    |   must start registration again. Default: 60 minutes.
    |
    | Example — strict password policy:
    |   AUTH_PASSWORD_MIN=12
    |   AUTH_PASSWORD_UPPERCASE=true
    |   AUTH_PASSWORD_NUMBER=true
    |   AUTH_PASSWORD_SPECIAL=true
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
    | Roles (Spatie Laravel Permission)
    |--------------------------------------------------------------------------
    |
    | default_role:
    |   The role that is automatically given to every new user after they
    |   verify their email (or sign in with Google for the first time).
    |   Make sure this role exists in your database — run AuthRolesSeeder first.
    |   Default: "user"
    |
    | seeded_roles:
    |   The roles that AuthRolesSeeder creates in your database.
    |   You can add more roles to the seeder if your app needs them.
    |   Default: ['super-admin', 'admin', 'user']
    |
    | Example:
    |   AUTH_DEFAULT_ROLE=member   → new users get the "member" role instead
    */
    'roles' => [
        'default_role' => env('AUTH_DEFAULT_ROLE', 'user'),
        'seeded_roles'  => ['super-admin', 'admin', 'user'],
    ],

    /*
    |--------------------------------------------------------------------------
    | OTP Channel / Driver
    |--------------------------------------------------------------------------
    |
    | Controls HOW OTP codes and magic links are delivered to the user.
    |
    | "email" → Use the built-in email system (default).
    |
    | Custom class → Provide a fully-qualified class name (FQCN) that implements
    |   OtpChannelContract. Use this to send OTPs via SMS, WhatsApp, etc.
    |
    | Example:
    |   AUTH_OTP_CHANNEL=email
    |   AUTH_OTP_CHANNEL=App\Channels\SmsOtpChannel
    */
    'otp_channel' => [
        'driver' => env('AUTH_OTP_CHANNEL', 'email'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Email Templates
    |--------------------------------------------------------------------------
    |
    | There are two ways to customise the emails this library sends.
    |
    | --- Option 1: Publish and edit the Blade views (easy, no PHP needed) ---
    |   Run: php artisan vendor:publish --tag=auth-views
    |   Then edit the files in resources/views/vendor/laravel-auth/emails/
    |
    |   Six templates, one per feature:
    |     otp-verify.blade.php           → OTP code for email verification
    |     otp-reset.blade.php            → OTP code for password reset
    |     magic-link-verify.blade.php    → Magic link for email verification
    |     magic-link-reset.blade.php     → Magic link for password reset
    |     otp-verify-combined.blade.php  → OTP + magic link for verification  (method=both)
    |     otp-reset-combined.blade.php   → OTP + magic link for password reset (method=both)
    |
    | --- Option 2: Custom notification class (full control) ---
    |   Point any of the keys below to your own Notification class FQCN.
    |   Your class constructor must accept: ($code, $type, $context)
    |   For combined: ($code, $url, $type, $context)
    |   When set, your class is used instead of the default + Blade view.
    |
    | NOTE: Option 2 takes priority over Option 1 for that specific email.
    | You can mix — e.g. override only the reset email and leave the rest as-is.
    |
    | Example:
    |   'otp_reset_notification' => \App\Notifications\MyResetEmail::class,
    */
    'mail' => [
        'otp_verify_notification'          => null,
        'otp_reset_notification'           => null,
        'magic_link_verify_notification'   => null,
        'magic_link_reset_notification'    => null,
        'otp_verify_combined_notification' => null,
        'otp_reset_combined_notification'  => null,

        /*
        | Account lifecycle notifications (v2.4). Same override pattern as the
        | OTP/magic-link entries above — set any key to your own Notification
        | FQCN to replace the bundled one. Leave as null to use the default.
        |
        | The "enabled" map toggles individual notifications without removing
        | the listener wiring. Set false to silence a specific event.
        */
        'account_deleted_notification'         => null,
        'account_restored_notification'        => null,
        'account_purged_notification'          => null,
        'account_status_changed_notification'  => null,
        'account_deactivated_notification'     => null,
        'account_reactivated_notification'     => null,

        'account_notifications_enabled' => [
            'deleted'        => true,
            'restored'       => true,
            'purged'         => false,
            'status_changed' => false,
            'deactivated'    => true,
            'reactivated'    => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Social / OAuth (Google)
    |--------------------------------------------------------------------------
    |
    | Enables "Sign in with Google" via Laravel Socialite.
    |
    | Set AUTH_GOOGLE_ENABLED=true and provide your Google OAuth credentials.
    | The library auto-configures config/services.php — you do not need to
    | edit it manually.
    |
    | How to get credentials:
    |   1. Go to https://console.cloud.google.com
    |   2. Create a project → Credentials → OAuth 2.0 Client ID
    |   3. Set the redirect URI to: https://your-app.com/auth/social/google/callback
    |
    | Example:
    |   AUTH_GOOGLE_ENABLED=true
    |   AUTH_GOOGLE_CLIENT_ID=123456789.apps.googleusercontent.com
    |   AUTH_GOOGLE_CLIENT_SECRET=GOCSPX-xxxx
    |   AUTH_GOOGLE_REDIRECT=https://myapp.com/auth/social/google/callback
    */
    'social' => [
        'google' => [
            'enabled'       => (bool) env('AUTH_GOOGLE_ENABLED', false),
            'client_id'     => env('AUTH_GOOGLE_CLIENT_ID'),
            'client_secret' => env('AUTH_GOOGLE_CLIENT_SECRET'),
            'redirect'      => env('AUTH_GOOGLE_REDIRECT'),
        ],

        // After a social account-link confirmation, where should the browser
        // be redirected? Set this to your frontend base URL.
        // When null, the library returns JSON instead of redirecting.
        // Example: AUTH_SOCIAL_FRONTEND_URL=https://myapp.com/auth/callback
        'frontend_url' => env('AUTH_SOCIAL_FRONTEND_URL', null),
    ],

    /*
    |--------------------------------------------------------------------------
    | Reverb (Real-time WebSocket Verification)
    |--------------------------------------------------------------------------
    |
    | When enabled, the library broadcasts an event over WebSocket as soon as
    | a user verifies their email. Your frontend can listen and react instantly
    | without the user needing to refresh the page.
    |
    | Requires: laravel/reverb to be installed and configured.
    |
    | How it works:
    |   1. User registers → receives a temp_token in the response
    |   2. Frontend subscribes to: Echo.private("auth.verification.{temp_token}")
    |   3. User verifies email → library broadcasts EmailVerified event
    |   4. Frontend receives the event and knows verification is complete;
    |      it then calls POST /auth/register/complete to finish registration
    |
    | Example:
    |   AUTH_REVERB_ENABLED=true
    */
    'reverb' => [
        'enabled' => (bool) env('AUTH_REVERB_ENABLED', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Email Verification Requirement
    |--------------------------------------------------------------------------
    |
    | Controls whether users must verify their email before they can log in.
    |
    | true  → User must verify email first. Login returns 403 if not verified.
    |         Recommended for public apps.
    |
    | false → User can log in immediately after registering, without verifying.
    |         Useful for internal tools or when verification is optional.
    |
    | Example:
    |   AUTH_REQUIRE_VERIFICATION=false   → skip verification (dev/internal tools)
    */
    'require_email_verification' => (bool) env('AUTH_REQUIRE_VERIFICATION', true),

    /*
    |--------------------------------------------------------------------------
    | API Token System
    |--------------------------------------------------------------------------
    |
    | The API token system lets users create long-lived, scoped tokens for
    | third-party integrations (CI pipelines, external services, scripts).
    | These are separate from Sanctum session tokens — they use the format
    | "auth_at_{base64}" and are stored in the auth_api_tokens table.
    |
    | This feature is DISABLED by default because most applications do not
    | need it, and it adds routes and a scheduled job. Enable it only when
    | you specifically need users to generate third-party API tokens.
    |
    | When enabled, the following routes become active:
    |   GET    /auth/api-tokens           → list user's tokens
    |   POST   /auth/api-tokens           → create a token
    |   DELETE /auth/api-tokens/{id}      → revoke a token
    |   GET    /auth/admin/api-tokens     → admin: list all tokens
    |   POST   /auth/admin/api-tokens     → admin: create unowned token
    |   PATCH  /auth/admin/api-tokens/{id} → admin: update token
    |   DELETE /auth/admin/api-tokens/{id} → admin: revoke any token
    |
    | When enabled, CleanExpiredApiTokens also runs hourly on the queue.
    |
    | Example:
    |   AUTH_API_TOKENS_ENABLED=true
    */
    'api_tokens' => [
        'enabled'           => (bool) env('AUTH_API_TOKENS_ENABLED', false),
        'abilities_default' => ['read'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    |
    | The library runs background maintenance jobs automatically:
    |   - CleanExpiredOtpRecords      (every 5 minutes) — always active
    |   - CleanExpiredRefreshTokens   (every hour)      — always active
    |   - CleanExpiredApiTokens       (every hour)      — only when api_tokens.enabled=true
    |
    | connection:
    |   Which queue connection to use for these jobs.
    |   Leave null to use your app's default queue connection.
    |   Example: "redis", "database", "sqs"
    |
    | name:
    |   The queue name these jobs are pushed onto.
    |   Run a worker for this queue to process them:
    |   php artisan queue:work --queue=auth-maintenance
    |
    | Example:
    |   AUTH_QUEUE_CONNECTION=redis
    |   AUTH_QUEUE_NAME=auth-maintenance
    */
    'queue' => [
        'connection' => env('AUTH_QUEUE_CONNECTION', null),
        'name'       => env('AUTH_QUEUE_NAME', 'auth-maintenance'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Response Formatter
    |--------------------------------------------------------------------------
    |
    | Every response from this library follows this structure by default:
    |   { "success": true, "message": "...", "data": {} }
    |   { "success": false, "message": "...", "errors": {} }
    |
    | If your app uses a different JSON structure, you can override it by
    | pointing this to your own class that implements ResponseFormatterContract.
    |
    | Your class must have this method:
    |   format(bool $success, string $message, array $data, array $errors): array
    |
    | Example:
    |   AUTH_RESPONSE_FORMATTER=App\Auth\MyResponseFormatter
    |
    | Leave empty (null) to use the default format.
    */
    'response' => [
        'formatter' => env('AUTH_RESPONSE_FORMATTER', null),
    ],

    /*
    |--------------------------------------------------------------------------
    | Security
    |--------------------------------------------------------------------------
    |
    | notify_new_device_login:
    |   When true, the user gets an email alert whenever they log in from a
    |   device (browser + OS) that the library has not seen before.
    |   This helps users spot unauthorised logins.
    |   Example: AUTH_NOTIFY_NEW_DEVICE=true
    |
    | lockout.enabled:
    |   When true, an account gets temporarily locked after too many failed
    |   login attempts. This is separate from rate limiting — rate limiting
    |   blocks by request speed, lockout blocks by total failure count.
    |   Example: AUTH_LOCKOUT_ENABLED=true
    |
    | lockout.max_attempts:
    |   How many failed logins are allowed before the account is locked.
    |   Example: AUTH_LOCKOUT_MAX=5  → lock after 5 wrong passwords
    |
    | lockout.decay_minutes:
    |   How many minutes the account stays locked.
    |   After this time, the user can try again automatically.
    |   Example: AUTH_LOCKOUT_DECAY=30  → locked for 30 minutes
    |
    | Recommended production settings:
    |   AUTH_NOTIFY_NEW_DEVICE=true
    |   AUTH_LOCKOUT_ENABLED=true
    |   AUTH_LOCKOUT_MAX=5
    |   AUTH_LOCKOUT_DECAY=30
    */
    /*
    |--------------------------------------------------------------------------
    | Account Status + Deletion (v2.4)
    |--------------------------------------------------------------------------
    |
    | Adds account lifecycle management: status gating at login + self-service
    | deletion with a grace period during which a regular login auto-restores
    | the account. After the grace period a scheduled worker nulls unique
    | columns (so the email/username can be reused) and optionally hard-deletes
    | the users row. A `deleted_accounts` table keeps a permanent audit
    | snapshot so foreign keys in app tables (orders, transactions, …) still
    | resolve to a meaningful record.
    |
    | --- status ---
    |   enabled:              Master switch for the status feature.
    |   column:               Column on users that stores the status string.
    |   default:              Status applied to brand-new users.
    |   allowed:              List of accepted statuses. Add custom ones here.
    |   login_blocked:        Statuses that reject login with the configured
    |                         message. ("deleted" is handled separately — the
    |                         login flow auto-restores within grace.)
    |   revoke_sessions_on_change:
    |                         When status leaves "active", drop all sanctum
    |                         tokens and AuthSessionExtended rows for the user.
    |   admin_ability:        Spatie permission/role name required to call the
    |                         admin status endpoints. Use any string your
    |                         host app already exposes.
    |
    | --- deletion ---
    |   enabled:              Master switch for the delete feature.
    |   self_service:         If true, expose DELETE /auth/account for users
    |                         to delete themselves. If false, only admins can
    |                         trigger deletion via the status endpoint.
    |   require_password:     Force the user to supply their password on the
    |                         delete call. Strongly recommended.
    |   grace_days:           How many days the account stays soft-deleted
    |                         before the worker purges it. Login during this
    |                         window auto-restores the account.
    |   auto_restore_on_login:
    |                         When true (recommended), a successful credential
    |                         check on a status=deleted account inside the
    |                         grace window restores it transparently. The
    |                         deleted_accounts row is dropped, status flips
    |                         back to "active" and the user is logged in.
    |   null_uniques_after_grace:
    |                         Worker nulls unique columns on the users row
    |                         after grace expires so the email/username can be
    |                         reclaimed by a new sign-up.
    |   hard_delete_after_grace:
    |                         Worker hard-deletes the users row after grace
    |                         expires. The deleted_accounts snapshot is kept
    |                         regardless so audit/FK targets survive.
    |   move_to_deleted_table:
    |                         Snapshot the full users row into deleted_accounts
    |                         on delete. Disable only if you have your own
    |                         audit table.
    |   unique_columns:       "auto" → introspect users-table indexes via
    |                         Schema::getIndexes() and null every single-column
    |                         unique index. Or pass an explicit array of column
    |                         names (e.g. ['email', 'username']).
    |   unique_exclude:       Columns the resolver must never null even if they
    |                         have a unique index (typically primary keys).
    */
    'account' => [
        'status' => [
            'enabled'                   => (bool) env('AUTH_ACCOUNT_STATUS_ENABLED', true),
            'column'                    => env('AUTH_ACCOUNT_STATUS_COLUMN', 'account_status'),
            'default'                   => env('AUTH_ACCOUNT_STATUS_DEFAULT', 'active'),
            'allowed'                   => ['active', 'disabled', 'suspended', 'deleted', 'deactivated'],
            'login_blocked'             => ['disabled', 'suspended'],
            // Statuses where a successful login silently flips the user back
            // to "active". `deleted` is also recognised here but is bounded
            // by the deletion grace window (handled separately in
            // account.deletion.auto_restore_on_login).
            'login_auto_restorable'     => ['deactivated'],
            'revoke_sessions_on_change' => (bool) env('AUTH_ACCOUNT_STATUS_REVOKE_ON_CHANGE', true),
            'admin_ability'             => env('AUTH_ACCOUNT_STATUS_ABILITY', 'super-admin|admin'),

            /*
            | Timed bans — admins can suspend / disable a user "until X" by
            | passing `expires_at` (ISO 8601) or `duration_minutes` to the
            | status endpoint. When the moment arrives the package flips the
            | user back to `active` automatically through two complementary
            | paths:
            |
            |   1. Lazy revert: every status read (login, middleware, /me)
            |      reverts on the spot if the expiry is in the past, so an
            |      unbanned user can log in the *instant* their ban expires.
            |   2. Scheduled sweep: every `sweep_minutes` minutes the
            |      RevertExpiredAccountStatuses worker reverts any rows the
            |      lazy path hasn't touched and fires AccountStatusChanged.
            |
            | Set enabled=false to disable the feature entirely — admins can
            | still set `status_expires_at` but nothing acts on it.
            */
            'auto_unban' => [
                'enabled'       => (bool) env('AUTH_ACCOUNT_AUTO_UNBAN', true),
                'sweep_minutes' => (int) env('AUTH_ACCOUNT_AUTO_UNBAN_SWEEP', 5),

                /*
                | Which statuses are "temporary ban" capable — i.e. the admin
                | endpoint will accept `expires_at` / `duration_minutes` for
                | them. Statuses NOT in this list are permanent-only: passing
                | an expiry alongside one returns 422.
                |
                | Omitting expires_at / duration_minutes on a temporary status
                | is still allowed and means a permanent ban (forever).
                |
                | Default: only "suspended" is timed-capable. "disabled" is
                | treated as a manual-action-required ban — admin must
                | explicitly reactivate.
                */
                'temporary_statuses' => ['suspended'],
            ],
        ],

        'deletion' => [
            'enabled'                  => (bool) env('AUTH_ACCOUNT_DELETE_ENABLED', true),
            'self_service'             => (bool) env('AUTH_ACCOUNT_DELETE_SELF', true),
            'require_password'         => (bool) env('AUTH_ACCOUNT_DELETE_REQUIRE_PASSWORD', true),
            'grace_days'               => (int) env('AUTH_ACCOUNT_DELETE_GRACE_DAYS', 30),
            'auto_restore_on_login'    => (bool) env('AUTH_ACCOUNT_AUTO_RESTORE', true),
            'null_uniques_after_grace' => (bool) env('AUTH_ACCOUNT_NULL_UNIQUES', true),
            'hard_delete_after_grace'  => (bool) env('AUTH_ACCOUNT_HARD_DELETE', false),
            'move_to_deleted_table'    => (bool) env('AUTH_ACCOUNT_AUDIT_TABLE', true),
            'unique_columns'           => env('AUTH_ACCOUNT_UNIQUE_COLUMNS', 'auto'),
            'unique_exclude'           => ['id'],
        ],

        /*
        | Self-service deactivation (Instagram-style "pause my account").
        |
        | When enabled, the user can POST /auth/account/deactivate to flip
        | their status to `deactivated`. All their tokens and sessions are
        | revoked so they appear logged out everywhere. The next time they
        | log in with the correct credentials the package silently flips
        | them back to `active` — there is no deadline, they can come back
        | months later. Distinct from `deleted` (which has a 30-day grace
        | and then permanently anonymises the row).
        |
        | Distinct from `disabled` too: `disabled` is an admin-only ban
        | (Meta-style for violations) and requires manual reactivation. The
        | appeal workflow that goes with `disabled` is a future release.
        */
        'deactivation' => [
            'enabled'                  => (bool) env('AUTH_ACCOUNT_DEACTIVATE_ENABLED', true),
            'self_service'             => (bool) env('AUTH_ACCOUNT_DEACTIVATE_SELF', true),
            'require_password'         => (bool) env('AUTH_ACCOUNT_DEACTIVATE_REQUIRE_PASSWORD', true),
            'auto_reactivate_on_login' => (bool) env('AUTH_ACCOUNT_AUTO_REACTIVATE', true),
        ],

        /*
        |----------------------------------------------------------------------
        | Audit log (multi-admin context)
        |----------------------------------------------------------------------
        |
        | Persists every status change + free-form admin notes so multiple
        | admins can see the full history of why an account is in its current
        | state and who touched it last. Like inline code comments for user
        | rows: "Sara disabled this 2026-05-17, comment: third strike, see
        | ticket #4711".
        |
        | Every flag below is opt-out — flip to false to silence that part of
        | the system. Disabling the master `enabled` flag makes the package
        | behave exactly like pre-audit v2.4 (no writes, endpoints return 404).
        |
        | log_system_actions:
        |   When false, only admin-initiated and user-initiated transitions
        |   are logged. Lazy auto-unban, sweep worker, login auto-restore /
        |   auto-reactivate, purge worker all stay silent. Useful if you want
        |   a smaller log focused on human actions.
        |
        | capture_request_meta:
        |   Capture ip_address + user_agent when there is an HTTP request in
        |   scope. CLI/queue/system actions never populate these.
        |
        | retention_days:
        |   null  → keep entries forever (default).
        |   N>0   → enable a daily cleanup that drops entries older than N
        |           days. Useful for GDPR-style data minimisation when your
        |           policy says you do not need indefinite admin history.
        */
        'audit' => [
            'enabled'              => (bool) env('AUTH_ACCOUNT_AUDIT_ENABLED', true),
            'table'                => env('AUTH_ACCOUNT_AUDIT_TABLE_NAME', 'account_status_logs'),
            'log_status_changes'   => (bool) env('AUTH_ACCOUNT_AUDIT_LOG_STATUS', true),
            'log_system_actions'   => (bool) env('AUTH_ACCOUNT_AUDIT_LOG_SYSTEM', true),
            'capture_request_meta' => (bool) env('AUTH_ACCOUNT_AUDIT_CAPTURE_META', true),
            'retention_days'       => env('AUTH_ACCOUNT_AUDIT_RETENTION_DAYS') !== null
                ? (int) env('AUTH_ACCOUNT_AUDIT_RETENTION_DAYS')
                : null,

            'notes' => [
                'enabled' => (bool) env('AUTH_ACCOUNT_AUDIT_NOTES_ENABLED', true),
            ],

            'history' => [
                'enabled'         => (bool) env('AUTH_ACCOUNT_AUDIT_HISTORY_ENABLED', true),
                'default_per_page' => (int) env('AUTH_ACCOUNT_AUDIT_HISTORY_PER_PAGE', 20),
                'max_per_page'     => (int) env('AUTH_ACCOUNT_AUDIT_HISTORY_MAX_PER_PAGE', 100),
            ],
        ],
    ],

    'security' => [
        'notify_new_device_login' => (bool) env('AUTH_NOTIFY_NEW_DEVICE', true),
        'lockout' => [
            'enabled'       => (bool) env('AUTH_LOCKOUT_ENABLED', true),
            'max_attempts'  => (int) env('AUTH_LOCKOUT_MAX', 10),
            'decay_minutes' => (int) env('AUTH_LOCKOUT_DECAY', 15),
        ],
    ],

];
