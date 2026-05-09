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
        'request_class' => null,
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
    'security' => [
        'notify_new_device_login' => (bool) env('AUTH_NOTIFY_NEW_DEVICE', true),
        'lockout' => [
            'enabled'       => (bool) env('AUTH_LOCKOUT_ENABLED', true),
            'max_attempts'  => (int) env('AUTH_LOCKOUT_MAX', 10),
            'decay_minutes' => (int) env('AUTH_LOCKOUT_DECAY', 15),
        ],
    ],

];
