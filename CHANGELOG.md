# Changelog

All notable changes to `joe-404/laravel-auth` are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/) and this project uses [Semantic Versioning](https://semver.org/).

---

## [2.0.0] — 2026-05-09

Security hardening pass. Many fixes are breaking — review before upgrading.

### Breaking

- `POST /auth/register` no longer returns 409 when the email already exists. The
  response shape is now identical for new and existing emails (a registered
  email instead receives an "you already have an account" notification
  out-of-band). This closes the registration enumeration oracle.
- Social-auth (`/auth/social/google/callback`) no longer auto-links a Google
  identity to a local account that shares the same email. The endpoint now
  returns 202 + `requires_link_confirmation` and emails a signed confirmation
  link to the registered address. Add a route handler for the new
  `/auth/social/{provider}/link/confirm/{token}` callback to your frontend.
- Magic-link click endpoints (`/auth/register/verify-magic`,
  `/auth/password/reset/magic`) now 302-redirect to the configured
  `frontend_verify_url` / `frontend_reset_url` (with `completion_token=` /
  `reset_token=` in the query) when the request comes from a browser. Set
  these env vars to keep the click-through flow working.
- `EmailVerificationController::resend` no longer returns the
  `temp_token_hint` field — it leaked the existence of pending registrations.
- `AuthService::logoutAll(User $user)` now requires `Request $request`
  as a second argument so it can preserve the calling session/token.
- `ConditionalCsrf` no longer exempts requests based on the `X-Client-Type`
  header (which any browser request can set). Only bearer-token requests
  bypass CSRF.
- `auth_refresh_tokens` schema gains `family_id`, `parent_id`, `revoked_at`,
  `revoked_reason` columns and a FK on `user_id`. `auth_otp_codes` gains
  `failed_attempts`. Run migrations.
- Minimum supported Laravel is now 12 (testbench 9/Laravel 11 path is dropped).

### Security fixes

- **OTP brute-force defense.** `OtpService::validateOtp` now atomically
  increments `failed_attempts` on each wrong submission and invalidates the
  active OTP after `auth_system.verification.otp_max_attempts` (default 5).
  New `auth.ratelimit:otp_verify` middleware (default `10:5`) is applied to
  `register/verify-otp`, `password/reset/otp`, and `password/reset/confirm`.
  In v1 the 6-digit OTP space was open to unlimited guessing.
- **Refresh-token rotation with reuse detection.** `TokenService::refresh`
  now tracks a `family_id`/`parent_id` lineage on every refresh token. A
  consumed token presented again revokes the entire family, killing both the
  attacker's and the legitimate client's current session — standard
  refresh-token-rotation hygiene.
- **`RateLimitAuth` no longer auto-clears the limiter on every 2xx response.**
  In v1 this defeated the limiter on always-200 endpoints (forgot-password,
  resend-verification). The clear is now triggered explicitly in
  `AuthService::login` only on a confirmed credential match.
- **`logoutAll` preserves the calling token/session** so the response itself
  is not 401'd. Previously, calling `/auth/logout/all` revoked the caller's
  own bearer token before the response was even returned.
- **Social account auto-linking by email is gone.** Email-match alone is
  insufficient proof of ownership; v2 requires the legitimate inbox owner
  to click a signed confirmation link before the link is created.
- **New-user social signup checks `email_verified` from the provider** when
  the OAuth payload carries it. Refuses signup for unverified provider emails.
- **Mass-assignment denylist.** `extra_fields` flowing into `User::create()`
  during registration are now stripped of `role`, `roles`, `is_admin`,
  `admin`, `email_verified_at`, `password`, and `password_change_required`
  before the create call.
- **`finalizeRegistration` is now wrapped in a DB transaction** so a failure
  mid-way no longer leaves a half-formed user.
- **Constant-time `forgotPassword`** for unknown emails (does a real
  `Hash::make` instead of a silent return) so timing does not leak whether
  the email exists.
- **`OtpService::generateOtp`** clamps `otp_length` to [4,8] before computing
  `str_repeat('9', N)`, avoiding integer overflow on misconfigured length.
- **`AuthServiceProvider::boot` validates `verification.otp_length` ∈ [4,8]**
  on boot and throws if misconfigured.

### Other

- `auth.feature:<name>` middleware gates feature-flagged routes at request
  time instead of at route-cache build time, so `php artisan route:cache`
  is now safe regardless of when feature flags are toggled.
- Fixed `auth_system.api_token.abilities_default` typo (was singular,
  config key is plural `api_tokens.abilities_default`).
- `auth_api_tokens.token_hash` is `char(64)` instead of `varchar(255)`.
- `AuthServiceProvider::boot` registers a default `api` named rate limiter
  and Spatie permission middleware aliases (`role`, `permission`,
  `role_or_permission`) when the host app has not already registered them.
- `CleanExpiredRefreshTokens` now also reaps consumed/revoked rows older
  than 7 days.
- `SessionService::deleteAll` collapses to a single delete pass (no TOCTOU
  window between read and delete).
- `AuthService::isNewDevice` matches on `device_code` for mobile clients
  and on `platform+browser+os` for web — fewer false positives than
  v1's browser+os-only check.
- New tests for OTP brute-force defense, registration enumeration parity,
  refresh-token reuse detection, rate-limit no-clear-on-success,
  social link takeover defense, and `logoutAll` self-preservation.
- `composer.json` adds a `scripts.test` shortcut.

---

## [1.0.0] — 2025-05-08

### Added

**Core authentication (M1)**
- `POST /auth/register` — initiates registration, stores credentials in cache, sends OTP + magic link simultaneously, returns `temp_token`
- `POST /auth/register/verify-otp` — completes registration via numeric OTP code
- `GET /auth/register/verify-magic/{token}` — completes registration via signed magic link
- `POST /auth/email/resend-verification` — resends OTP/magic link without revealing whether the email exists
- `POST /auth/login` — authenticates verified users, issues Sanctum Bearer token or session cookie depending on `AUTH_MODE`
- `POST /auth/logout` — revokes current token/session
- `POST /auth/logout/all` — revokes all sessions across all devices
- `GET /auth/me` — returns user profile, roles, permissions, and active session count

**Password management (M4)**
- `POST /auth/password/forgot` — sends OTP + magic link for password reset (email-enumeration-safe)
- `POST /auth/password/reset/otp` — resets password by submitting OTP + new password in one call
- `GET /auth/password/reset/magic/{token}` — validates signed reset URL, returns short-lived `reset_token`
- `POST /auth/password/reset/confirm` — sets new password using `reset_token`; revokes all existing sessions
- `POST /auth/password/change` — changes password for authenticated user; optional `logout_all` flag

**Session & device tracking (M2)**
- `GET /auth/sessions` — lists all active sessions with device, browser, OS, IP, and geo info
- `DELETE /auth/sessions/{id}` — revokes a specific session by ID
- Device detection from `User-Agent` (web) and `X-Device-Info` header (mobile)
- ~500-model device lookup via `resources/devices.json`
- Geo-location from `ip-api.com` (city + country, fails silently)

**API token system (M3)**
- `GET /auth/api-tokens` — lists all API tokens for the authenticated user
- `POST /auth/api-tokens` — creates a scoped, optionally expiring API token (`auth_at_` prefix)
- `DELETE /auth/api-tokens/{id}` — revokes a user-owned token
- `GET /auth/admin/api-tokens` — admin: lists all tokens across all users
- `POST /auth/admin/api-tokens` — admin: creates a token not tied to any user
- `PATCH /auth/admin/api-tokens/{id}` — admin: updates abilities or expiry
- `DELETE /auth/admin/api-tokens/{id}` — admin: revokes any token
- `ApiTokenAuth` middleware with per-ability checks (`auth.api-token:read,orders`)

**Google OAuth (M5)**
- `GET /auth/social/google/redirect` — returns Google authorization URL
- `GET /auth/social/google/callback` — handles OAuth exchange; creates, links, or logs in user automatically

**Real-time verification via Reverb (M6)**
- Broadcasts `EmailVerified` event on `auth.verification.{temp_token}` private channel when enabled
- Frontend receives Sanctum token in real time without polling
- `auth:install` appends Reverb channel stub to host app's `routes/channels.php`

**Security hardening (M7)**
- Dual-layer rate limiting: per-IP and per-email independently, on all public endpoints
- Account lockout: cumulative failure tracking across rate-limit windows (`AUTH_LOCKOUT_MAX`, `AUTH_LOCKOUT_DECAY`)
- New-device detection: `SuspiciousLoginDetected` event + `NewDeviceLoginNotification` email
- `RequireEmailVerified` middleware
- `DeviceFingerprint` middleware

**Infrastructure**
- `AuthServiceProvider` with auto-discovery (zero manual registration required)
- `php artisan auth:install` — publishes config, migrations, seeder, channel stub
- `AuthRolesSeeder` — creates `super-admin`, `admin`, `user` roles via Spatie Permission
- `CleanExpiredOtpRecords` job (every 5 min) and `CleanExpiredApiTokens` job (every hour)
- Customisable response envelope via `ResponseFormatterContract`
- Customisable OTP delivery via `OtpChannelContract` (swap email for SMS, WhatsApp, etc.)
- Full Pest test suite: unit + feature tests with `Mail::fake()` and `Queue::fake()`
- `declare(strict_types=1)` and return types on every method; Octane singleton-safe services
