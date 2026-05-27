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
    | reward_handler:
    |   FQCN of a class implementing ReferralRewardHandlerContract. When a
    |   referral becomes "valid" (passes abuse checks), the package calls
    |   handle(Referral $referral) on this class. The developer owns the
    |   reward logic — credit a wallet, send a subscription, issue a
    |   discount coupon, etc. Leave null to fire events only.
    |
    | redeem_window_minutes:
    |   How long after registration the new user can call the redeem
    |   endpoint to submit a code they forgot to enter. Outside this
    |   window the endpoint returns a clear error. Default: 120 (2 hours).
    |
    | allowed_clients:
    |   Restrict referral submission/redemption to specific client types.
    |   - "both"   → web/SPA + mobile (default)
    |   - "web"    → only browser/SPA requests (no X-Client-Type: mobile)
    |   - "mobile" → only requests with X-Client-Type: mobile
    |   Requests from disallowed client types fail silently (success
    |   response, nothing stored, no event fired).
    |
    | abuse.on_same_ip / on_same_device / on_same_ip_and_device:
    |   How to react when the new user's fingerprint matches the referrer.
    |   - "block"  → store referral with status=blocked, no reward
    |   - "flag"   → store referral with status=suspicious, no reward,
    |               fires SuspiciousReferralDetected event for admin review
    |   - "ignore" → treat as a valid referral, reward fires
    |   Registration is NEVER blocked — only the reward.
    |
    | Example:
    |   AUTH_REFERRAL_CODE_ENABLED=true
    |   AUTH_REFERRAL_CODE_LENGTH=8
    |   AUTH_REFERRAL_REWARD_HANDLER=App\Auth\GiveCreditReward
    |   AUTH_REFERRAL_ALLOWED_CLIENTS=both
    */
    'referral_code' => [
        'enabled'               => (bool) env('AUTH_REFERRAL_CODE_ENABLED', false),
        'column'                => env('AUTH_REFERRAL_CODE_COLUMN', 'referral_code'),
        'length'                => (int) env('AUTH_REFERRAL_CODE_LENGTH', 10),
        'uppercase'             => (bool) env('AUTH_REFERRAL_CODE_UPPERCASE', true),
        'generator'             => env('AUTH_REFERRAL_CODE_GENERATOR', null),
        'reward_handler'        => env('AUTH_REFERRAL_REWARD_HANDLER', null),
        'redeem_window_minutes' => (int) env('AUTH_REFERRAL_REDEEM_WINDOW', 120),
        'allowed_clients'       => env('AUTH_REFERRAL_ALLOWED_CLIENTS', 'both'),

        'abuse' => [
            'on_same_ip'            => env('AUTH_REFERRAL_ABUSE_SAME_IP', 'flag'),
            'on_same_device'        => env('AUTH_REFERRAL_ABUSE_SAME_DEVICE', 'block'),
            'on_same_ip_and_device' => env('AUTH_REFERRAL_ABUSE_BOTH', 'block'),
        ],

        // Header the frontend uses to send a strong browser fingerprint hash
        // computed client-side (canvas + WebGL + screen + timezone, etc.).
        // The package never computes this — see docs/referral-codes.md for
        // the JS snippet to drop into your frontend.
        'browser_fingerprint_header' => env('AUTH_REFERRAL_FP_HEADER', 'X-Browser-Fingerprint'),
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
        // Referral codes
        'referral_code_not_found'      => null,
        'referral_self_referral'       => null,
        'referral_already_redeemed'    => null,
        'referral_window_expired'      => null,
        'referral_blocked_same_device' => null,
        'referral_blocked_same_ip'     => null,
        'referral_blocked'             => null,
        'referral_not_found'           => null,
        // Device history
        'device_not_found'             => null,
    ],

    'messages' => [
        'register_initiated'     => null,
        'register_verified'      => null,
        'register_complete'      => null,
        'social_profile_completion_required' => null,
        'verification_resent'    => null,
        'login_success'          => null,
        'me_retrieved'           => null,
        'logout_success'         => null,
        'logout_all_success'     => null,
        'session_cleared'        => null,
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
        // Referral codes
        'referral_redeemed'      => null,
        'referrals_retrieved'    => null,
        'referral_stats_retrieved' => null,
        'referral_status_updated'  => null,
        // Device history
        'devices_retrieved'        => null,
        'device_forgotten'         => null,
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
        // Step-up password confirmation. Throttled per authenticated user (not
        // by IP) so a hijacked session cannot brute-force the account password
        // to obtain a sudo window, even from a rotating IP pool.
        'password_confirm' => env('AUTH_RATE_PASSWORD_CONFIRM', '5:1'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Password Policy
    |--------------------------------------------------------------------------
    |
    | Rules enforced when a user registers or changes their password.
    |
    | min_length:         Minimum number of characters. Default: 15.
    |                      Aligns with NIST SP 800-63B-4, which recommends a
    |                      15-character minimum for single-factor passwords (and
    |                      discourages forced composition rules). This package
    |                      allows single-factor login, so 15 is the safe default.
    |                      Lower it with AUTH_PASSWORD_MIN if you need to (the
    |                      hard floor enforced at boot is 8).
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
        'min_length'          => (int) env('AUTH_PASSWORD_MIN', 15),
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

        /*
        | Enforce an OAuth `state` (and PKCE) check even for stateless clients
        | (mobile / api / spa_token), where Socialite's ->stateless() otherwise
        | disables CSRF state validation. BREAKING when enabled — defaults off
        | so existing mobile/SPA clients keep working. When true the package
        | persists a server-generated state for the flow and verifies it on
        | callback; the client must round-trip the value. Recommended on.
        */
        'enforce_state' => (bool) env('AUTH_SOCIAL_ENFORCE_STATE', false),

        /*
        |----------------------------------------------------------------------
        | Profile completion after social sign-in (v2.6)
        |----------------------------------------------------------------------
        |
        | OAuth gives you the user's identity (email, name) but never your
        | app's custom registration fields (username, phone, country, …).
        | When you require such fields, a brand-new Google user has no way to
        | supply them during the OAuth round-trip.
        |
        | When `enabled` is true, the social callback for a BRAND-NEW user
        | does NOT create the account or log them in. Instead it returns:
        |
        |   { status: "requires_profile_completion", completion_token,
        |     prefill: { email, name, avatar } }
        |
        | The frontend collects the required fields and POSTs them to
        | `POST /auth/social/complete` with the completion_token. That endpoint
        | validates them against the SAME `registration.extra_fields_rules`
        | (and phone rules) the email flow uses, then creates the user, links
        | the social account, and issues the real token. No user row is created
        | until completion — so an abandoned onboarding leaves nothing behind,
        | exactly like the 3-step email registration.
        |
        | Only fields marked `required` in extra_fields_rules (plus `phone`
        | when phone.required=true) are enforced; optional fields can be filled
        | later from a profile screen. Phone is captured here but verified
        | through the normal /auth/phone flow afterward.
        |
        | Leave disabled (default) to keep the legacy behavior where social
        | sign-in creates + logs in the user immediately from the Google
        | profile alone.
        */
        'profile_completion' => [
            'enabled'     => (bool) env('AUTH_SOCIAL_PROFILE_COMPLETION', false),
            'ttl_minutes' => (int) env('AUTH_SOCIAL_PROFILE_COMPLETION_TTL', 15),
        ],
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

        /*
        | Token purpose. Documents (and, under strict mode, constrains) how the
        | host treats user-created tokens:
        |   "customer_auth" → the token authenticates AS the user (it inherits
        |                     the owner via ApiTokenAuth). Legacy behavior and
        |                     the default.
        |   "third_party"   → machine-to-machine tokens (CI, scripts) that should
        |                     carry only narrow, explicit abilities.
        */
        'mode' => env('AUTH_API_TOKENS_MODE', 'customer_auth'),

        /*
        | Abilities a NON-admin user may request on POST /auth/api-tokens when
        | strict_abilities=true. The wildcard "*" is never self-grantable — it
        | is reserved for admin-issued tokens. Add the ability strings your host
        | routes check for (e.g. 'read', 'read:orders').
        */
        'grantable_abilities' => ['read'],

        /*
        | Strict ability enforcement. BREAKING when enabled — defaults off to
        | preserve v2.6 behavior. When true, a normal user may only request
        | abilities listed in grantable_abilities and can never self-grant "*"
        | or anything outside the list; admin token creation is unaffected.
        */
        'strict_abilities' => (bool) env('AUTH_API_TOKENS_STRICT', false),

        /*
        | Require a fresh step-up (sudo password / 2FA per step_up_mode) before
        | a logged-in session can mint a long-lived API token. BREAKING when
        | enabled — defaults off. Recommended on so a hijacked session cannot
        | silently create a persistent token.
        */
        'require_step_up' => (bool) env('AUTH_API_TOKENS_REQUIRE_STEP_UP', false),

        /*
        | Optional hard cap (days) on a created token's lifetime. null keeps the
        | current behavior (tokens may be non-expiring). Set e.g. 365 to forbid
        | never-expiring tokens.
        */
        'max_ttl_days' => env('AUTH_API_TOKENS_MAX_TTL_DAYS') !== null
            ? (int) env('AUTH_API_TOKENS_MAX_TTL_DAYS')
            : null,
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

            // When true, the admin "change status" endpoint also requires a
            // fresh step-up (sudo password or 2FA per two_factor.step_up_mode)
            // on top of the role gate. Default false to stay backward-
            // compatible — enabling it is a behavior change for admin clients,
            // which must call POST /auth/password/confirm first.
            'require_step_up'           => (bool) env('AUTH_ACCOUNT_STATUS_REQUIRE_STEP_UP', false),

            /*
            | Admin action guardrails for the status endpoints. BREAKING when
            | enforce_role_hierarchy is enabled — defaults off to preserve v2.6
            | behavior (any admin/super-admin could change anyone, including
            | peers, higher roles, and themselves).
            |
            | When enforce_role_hierarchy=true the actor may only change a user
            | whose highest role rank is strictly BELOW the actor's:
            |   - allow_self_action=false → cannot change your own status
            |   - allow_equal_rank=false  → cannot change a same-rank admin
            | role_ranks maps role name → integer rank (higher = more power);
            | roles not listed rank as 0.
            */
            'admin_actions' => [
                'enforce_role_hierarchy' => (bool) env('AUTH_ACCOUNT_STATUS_HIERARCHY', false),
                'allow_self_action'      => (bool) env('AUTH_ACCOUNT_STATUS_ALLOW_SELF', false),
                'allow_equal_rank'       => (bool) env('AUTH_ACCOUNT_STATUS_ALLOW_EQUAL', false),
                'role_ranks'             => [
                    'super-admin' => 100,
                    'admin'       => 50,
                ],
            ],

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

            // What the failed-attempt counter is keyed on. Defaults to 'email'
            // (v2.6 behavior). 'email' alone lets an attacker who knows a
            // victim's address lock them out (targeted DoS); 'email_and_ip'
            // only locks a given email FROM a given IP; 'ip' keys purely on
            // source. One of: 'email' | 'ip' | 'email_and_ip'.
            'scope'         => env('AUTH_LOCKOUT_SCOPE', 'email'),

            // Apply increasing back-off delay as failures accumulate instead of
            // a single hard lock. Defaults off (hard lock) to match v2.6.
            'backoff'       => (bool) env('AUTH_LOCKOUT_BACKOFF', false),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Phone Number (v2.6)
    |--------------------------------------------------------------------------
    |
    | Optional phone capture at registration plus phone verification via SMS,
    | voice, or WhatsApp. Phone is NOT used for login in this release — it is
    | stored on the user, optionally verified, and feeds the 2FA "sms" method.
    |
    | enabled:
    |   Master switch for the phone feature. When false the registration form
    |   ignores the phone field entirely and verification endpoints 404.
    |
    | required:
    |   When true, registration FAILS without a phone. When false, phone is
    |   optional (user can submit it or omit it). MVP default: false.
    |
    | column:
    |   Users-table column that stores the E.164 phone. Migration adds it.
    |
    | verification.required_at_registration:
    |   When true, the user must verify the phone (OTP via the default channel)
    |   before the account is fully active — registration response includes a
    |   phone challenge token. When false, phone is saved unverified and the
    |   developer can trigger verification later via /auth/phone/send-otp.
    |
    | providers / channels:
    |   Per-channel provider selection with optional fallback. Each provider
    |   maps to a driver class implementing PhoneDriverContract. Default is
    |   the "log" driver which writes codes to the Laravel log — safe for
    |   development. Production must override with real provider credentials.
    |
    | Custom provider example:
    |   'providers' => [
    |       'my_vonage' => [
    |           'driver'  => \App\Phone\VonageDriver::class,
    |           'api_key' => env('VONAGE_API_KEY'),
    |       ],
    |   ],
    |   'channels' => [
    |       'sms' => ['provider' => 'my_vonage', 'fallback' => 'log'],
    |   ],
    */
    'phone' => [
        'enabled'  => (bool) env('AUTH_PHONE_ENABLED', false),
        'required' => (bool) env('AUTH_PHONE_REQUIRED', false),
        'column'   => env('AUTH_PHONE_COLUMN', 'phone'),

        'verification' => [
            'required_at_registration' => (bool) env('AUTH_PHONE_VERIFY_AT_REG', false),
            'default_channel'          => env('AUTH_PHONE_VERIFY_CHANNEL', 'sms'),
            'otp_length'               => (int) env('AUTH_PHONE_OTP_LENGTH', 6),
            'otp_expiry_minutes'       => (int) env('AUTH_PHONE_OTP_EXPIRY', 5),
            'max_attempts'             => (int) env('AUTH_PHONE_OTP_MAX_ATTEMPTS', 5),
        ],

        'providers' => [
            'log' => [
                'driver' => \Joe404\LaravelAuth\Phone\Drivers\LogPhoneDriver::class,
            ],
            'infobip' => [
                'driver'   => \Joe404\LaravelAuth\Phone\Drivers\InfobipDriver::class,
                'api_key'  => env('INFOBIP_API_KEY'),
                'base_url' => env('INFOBIP_BASE_URL', 'https://api.infobip.com'),
                'sender'   => env('INFOBIP_SENDER'),
            ],
            'messagecentral' => [
                'driver'      => \Joe404\LaravelAuth\Phone\Drivers\MessageCentralDriver::class,
                'customer_id' => env('MC_CUSTOMER_ID'),
                'password'    => env('MC_PASSWORD'),
                'base_url'    => env('MC_BASE_URL', 'https://cpaas.messagecentral.com'),
            ],
            'twilio' => [
                'driver' => \Joe404\LaravelAuth\Phone\Drivers\TwilioDriver::class,
                'sid'    => env('TWILIO_SID'),
                'token'  => env('TWILIO_TOKEN'),
                'from'   => env('TWILIO_FROM'),
            ],
            'firebase' => [
                'driver'         => \Joe404\LaravelAuth\Phone\Drivers\FirebaseDriver::class,
                'project_id'     => env('FIREBASE_PROJECT_ID'),
                'credentials'    => env('FIREBASE_CREDENTIALS'),
            ],
        ],

        // No provider defaults to a real value on purpose: you must opt into a
        // provider explicitly. The `log` driver writes codes to the Laravel
        // log and only runs in local/testing — never default a real-code
        // channel to it. Set e.g. AUTH_PHONE_SMS_PROVIDER=infobip in prod, or
        // AUTH_PHONE_SMS_PROVIDER=log for local development.
        'channels' => [
            'sms' => [
                'provider' => env('AUTH_PHONE_SMS_PROVIDER'),
                'fallback' => env('AUTH_PHONE_SMS_FALLBACK'),
            ],
            'voice' => [
                'provider' => env('AUTH_PHONE_VOICE_PROVIDER'),
                'fallback' => env('AUTH_PHONE_VOICE_FALLBACK'),
            ],
            'whatsapp' => [
                'provider' => env('AUTH_PHONE_WHATSAPP_PROVIDER'),
                'fallback' => env('AUTH_PHONE_WHATSAPP_FALLBACK'),
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Two-Factor Authentication (v2.6)
    |--------------------------------------------------------------------------
    |
    | Three methods are supported and can be enrolled in parallel:
    |   - totp:  RFC 6238 authenticator app (Google Authenticator, Authy, 1Password)
    |   - email: OTP delivered to the user's verified email
    |   - sms:   OTP delivered to the user's verified phone via the phone driver
    |
    | At login time, the user picks any enrolled method (or supplies a backup
    | code). Trusted devices at level >= bypass_2fa_min_level skip the challenge.
    |
    | required:
    |   When true, every user must enroll in at least one 2FA method on their
    |   next login. Combine with admin-forced flag (users.two_factor_required)
    |   for per-user enforcement.
    |
    | middleware_behavior:
    |   How the Require2FA middleware reacts when the current session has not
    |   completed 2FA (or the user has no 2FA enrolled):
    |     - "block"            → 403, client must redirect to enrollment
    |     - "force_enroll"     → returns enroll_token + redirect hint
    |     - "password_confirm" → GitHub-style sudo mode: re-enter password
    |                            grants a confirm_token valid for sudo_ttl_minutes
    |
    | rate_limits:
    |   "max_attempts:decay_minutes". Challenge invalidation also applies
    |   independently at challenge.max_attempts (5 wrong codes → new login).
    */
    'two_factor' => [
        'enabled'        => (bool) env('AUTH_2FA_ENABLED', true),
        'required'       => (bool) env('AUTH_2FA_REQUIRED', false),
        'methods'        => ['totp', 'email', 'sms'],
        'default_method' => env('AUTH_2FA_DEFAULT', 'totp'),

        'challenge' => [
            'ttl_seconds'           => (int) env('AUTH_2FA_CHALLENGE_TTL', 300),
            'max_attempts'          => (int) env('AUTH_2FA_CHALLENGE_MAX_ATTEMPTS', 5),
            // Burst limit applied per-challenge_token (NOT per-IP) — a leaked
            // token cannot be brute-forced even from a rotating IP pool.
            'burst_max_per_minute'  => (int) env('AUTH_2FA_CHALLENGE_BURST', 10),
        ],

        'codes' => [
            'totp' => [
                'issuer' => env('AUTH_2FA_TOTP_ISSUER', env('APP_NAME', 'Laravel')),
                'digits' => 6,
                'period' => 30,
                'window' => 1, // accept +/- N steps from current time
            ],
            'email' => [
                'length'         => 6,
                'expiry_minutes' => 10,
            ],
            'sms' => [
                'length'         => 6,
                'expiry_minutes' => 5,
                'channel'        => 'sms', // sms|voice|whatsapp
            ],
        ],

        'backup_codes' => [
            'enabled' => (bool) env('AUTH_2FA_BACKUP_ENABLED', true),
            'count'   => (int) env('AUTH_2FA_BACKUP_COUNT', 8),
            'length'  => (int) env('AUTH_2FA_BACKUP_LENGTH', 10),
        ],

        'middleware_behavior' => env('AUTH_2FA_MIDDLEWARE', 'password_confirm'),
        'sudo_ttl_minutes'    => (int) env('AUTH_2FA_SUDO_TTL', 15),

        // How the `auth.step-up` middleware re-verifies identity before a
        // sensitive action (remove a 2FA method, regenerate backup codes,
        // change phone, admin status change):
        //   "password_confirm" → user re-enters their password via
        //                        POST /auth/password/confirm (15-min sudo
        //                        window). Works for users with or without 2FA.
        //   "two_factor"       → user must pass a fresh 2FA challenge (falls
        //                        back to password_confirm when the user has no
        //                        2FA method enrolled).
        // A recent successful 2FA challenge (the login sudo stamp) also
        // satisfies the gate in both modes.
        'step_up_mode' => env('AUTH_2FA_STEP_UP_MODE', 'password_confirm'),

        'rate_limits' => [
            'challenge' => env('AUTH_2FA_RATE_CHALLENGE', '5:5'),
            'enroll'    => env('AUTH_2FA_RATE_ENROLL', '5:10'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Trusted Devices (v2.6)
    |--------------------------------------------------------------------------
    |
    | A trusted device skips the 2FA challenge at login when its current trust
    | level meets `bypass_2fa_min_level`. Trust accrues over time, can be
    | granted explicitly by the user at the post-login prompt, and is auto-
    | granted to the registration device (level: high) so the first login is
    | frictionless.
    |
    | level_assignment:
    |   "time"            → pure time-based progression by last_seen_at
    |   "time_consistent" → same as "time" but resets progress if the device
    |                       disappears for more than consistency.max_absence_days
    |   "time_admin"      → time handles low+medium; high requires an admin
    |                       PATCH /auth/admin/trusted-devices/{id}/level call
    |
    | bypass_2fa_min_level:
    |   "low" | "medium" | "high". Devices BELOW this level still get a
    |   challenge at every login.
    */
    'trusted_devices' => [
        'enabled'                        => (bool) env('AUTH_TRUSTED_DEVICES_ENABLED', true),
        'level_assignment'               => env('AUTH_TRUST_LEVEL_MODE', 'time'),
        'auto_trust_registration_device' => (bool) env('AUTH_TRUST_REG_DEVICE', true),

        // Initial trust level RECORDED for the device that completes
        // registration. Note: the EFFECTIVE level that governs 2FA bypass is
        // recomputed from elapsed time by TrustLevelResolver — a brand-new
        // device resolves to 'low' and earns higher trust over the
        // thresholds_days schedule — so under the default bypass_2fa_min_level
        // of 'high' a freshly-registered device does NOT bypass 2FA regardless
        // of this value. It sets the stored starting level (relevant to custom
        // resolvers and the admin-granted path). One of: 'low'|'medium'|'high'.
        'registration_device_level' => env('AUTH_TRUST_REG_DEVICE_LEVEL', 'high'),

        // Devices BELOW this level get a 2FA challenge at every login. Default
        // is `high` so the bypass requires the strongest trust signal the
        // package recognises (90 days of usage, registration device, or admin
        // grant) — not just "the user opted in once". Combine with the
        // server-issued device token requirement below for true defense in
        // depth against client-supplied-fingerprint forgery.
        'bypass_2fa_min_level'           => env('AUTH_TRUST_BYPASS_MIN', 'high'),

        // Header the client sends back to prove possession of the server-
        // issued device token. The plaintext is returned exactly once at the
        // moment the device is trusted (registration response, or 2FA
        // challenge response when trust_device=true) — store it in mobile
        // Keychain / SPA HttpOnly cookie. Without this header, fingerprint
        // alone never grants 2FA bypass.
        'token_header'                   => env('AUTH_TRUST_TOKEN_HEADER', 'X-Trusted-Device-Token'),

        'thresholds_days' => [
            'low'    => (int) env('AUTH_TRUST_LOW_DAYS', 15),
            'medium' => (int) env('AUTH_TRUST_MEDIUM_DAYS', 60),
            'high'   => (int) env('AUTH_TRUST_HIGH_DAYS', 90),
        ],

        'consistency' => [
            'max_absence_days' => (int) env('AUTH_TRUST_MAX_ABSENCE', 30),
        ],

        'admin_grant_high' => (bool) env('AUTH_TRUST_ADMIN_GRANT_HIGH', false),
    ],

];
