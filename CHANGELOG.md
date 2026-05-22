# Changelog

All notable changes to `joe-404/laravel-auth` are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/) and this project uses [Semantic Versioning](https://semver.org/).

---

## [2.5.1] — 2026-05-22

Security and correctness pass on the refresh-token flow, plus a handful of
hardening fixes around device fingerprinting, error responses, and config
validation. **No breaking changes**, no migrations.

### Fixed

- **Refresh now re-validates account status.** `TokenService::refresh()` used
  to mint new tokens without re-checking whether the user was still allowed
  to authenticate — a suspended, disabled, or soft-deleted user could keep
  rotating tokens for the lifetime of the refresh window. The flow now
  re-runs `AccountStatusService::assertCanLogin()`, `hasVerifiedEmail()` (if
  enabled), and `trashed()` before issuing the new pair.
- **Refresh rotation is now properly atomic.** Reuse detection used to read
  the token row outside the rotation transaction, so two concurrent legitimate
  refreshes could both pass the `consumed` check and only one would get
  through — the other returned a generic "Invalid refresh token" without
  triggering family revocation. The row is now `lockForUpdate`-selected
  inside the transaction; any presentation of a consumed token revokes the
  whole family, matching RFC 6749 §10.4 strict rotation.
- **Refresh now updates the session record.** Previously, `AuthService::refreshToken()`
  rotated the Sanctum token but left `auth_sessions_extended.sanctum_token_id`
  pointing at the now-deleted old token — so `/auth/sessions`, session
  revocation, and last-active tracking silently broke after the first
  refresh. The session row is now re-pointed at the new token (or created
  if missing).
- **`isAuthRoute()` honors the configured route prefix.** The exception
  renderer used to hardcode `auth/`, so hosts mounting the package under a
  custom prefix (`api/v1/auth`, etc.) lost the JSON envelope around
  `ValidationException` and `AuthenticationException`. It now reads
  `auth_system.routes.prefix`; for root-mounted setups it falls back to
  matching named routes starting with `auth.`.
- **Frontend magic-link URL is now validated.** When `magic_link_target=frontend`
  but `frontend_verify_url` / `frontend_reset_url` was empty or malformed,
  emails went out with broken links (`?token=...` with no host). The package
  now throws the new `AuthConfigurationException` at link-generation time.
- **`ApiTokenAuth` no longer leaks internal exception messages.** Unknown
  exceptions during token validation used to be returned verbatim in the
  response body — exposing SQL fragments, file paths, or stack-trace hints
  to the caller. Known `AuthException` instances still pass through with
  their safe message; unknown errors are logged and replaced with a
  generic `"Invalid API token."`.
- **Browser fingerprint header is now format-validated.** The
  `X-Browser-Fingerprint` value used to be accepted verbatim (truncated to
  191 chars). It must now be a hex digest within `[min, max]` characters
  (defaults 32 and 128), otherwise it is treated as absent — preventing
  arbitrary binary blobs, control characters, or oversized payloads from
  being stored in the column. **The fingerprint is still advisory and
  must not be treated as proof of device identity.**
- **GeoIP lookup no longer blocks the auth path and uses HTTPS.** The
  third-party IP-to-location call (off by default) was made over `http://`
  on the synchronous request path, adding up to 3s of latency per login.
  It is now dispatched as the new `BackfillSessionLocation` job, which
  fills `country` and `city` on the session row after creation; the
  default endpoint is `https://ip-api.com/json/{ip}` and is overridable.
- **Removed unused `use Mockery;` in `tests/Feature/Auth/SocialAuthTest.php`**
  — PHP was emitting a noisy "use statement … has no effect" warning on
  every test run.

### Added

- **`AuthConfigurationException`** — thrown for programmer-facing
  configuration errors (currently: missing frontend magic-link URL).
  Subclass of `AuthException`, default error key `auth_misconfigured`.
- **`BackfillSessionLocation` job** — queues the GeoIP lookup off the
  auth request path. Dispatched by `SessionService::create()` only when
  `auth_system.device.resolve_location=true`, the IP is public, and the
  session row was not pre-populated by a host-app resolver.

### New optional config keys

All have safe defaults — nothing to change unless you want to override:

```php
'verification' => [
    // When true (default), TokenService::refresh refuses to mint new
    // tokens for an unverified user. Set false to keep the legacy
    // behavior where verification is only enforced at login.
    'required_for_refresh' => true,
],

'referral_code' => [
    'browser_fingerprint_min_length' => 32,
    'browser_fingerprint_max_length' => 128,
],

'device' => [
    'location_endpoint' => 'https://ip-api.com/json/{ip}',
    'location_queue'    => 'default',
],
```

---

## [2.5.0] — 2026-05-20

Permanent device history, browser/mobile fingerprinting, and a full
referral code system with config-driven anti-abuse detection.

### Added

- **Permanent device history.** Every successful login upserts a row in the
  new `auth_user_devices` table. Records survive logout and session revocation,
  giving users a full audit trail of every device that has ever accessed their
  account.
  - `GET /auth/devices` — list every historical device with first/last seen timestamps.
  - `DELETE /auth/devices/{id}` — forget a device and revoke any active sessions
    whose `fingerprint_hash` matches that device record.
- **Browser and mobile fingerprinting.** `DeviceService` reads a
  `fingerprint_hash` from the `X-Browser-Fingerprint` header (browser/SPA) or
  from `device_id` inside the `X-Device-Info` header (mobile). The hash is
  stored on both `auth_sessions_extended` (new `fingerprint_hash` column) and
  `auth_user_devices`. A `device_signature` is derived by priority: fingerprint
  hash → device code SHA-256 → browser+OS+platform SHA-256, which de-duplicates
  records across reinstalls and browser-clears on the same physical device.
- **Referral code system.**
  - Auto-generate a unique referral code per user at registration (configurable
    length, uppercase toggle, custom generator via `ReferralCodeGeneratorContract`).
  - `POST /auth/referrals/redeem` — submit a referral code after registration.
    Must be redeemed within `AUTH_REFERRAL_REDEEM_WINDOW` minutes.
  - `GET /auth/referrals` — list the authenticated user's outgoing referrals
    and their statuses.
  - `GET /auth/referrals/stats` — aggregate counts per status.
  - `GET /auth/admin/referrals` — paginated list of all referrals, filterable
    by `?status=`.
  - `PATCH /auth/admin/referrals/{id}` — admin override of referral status and
    note. Transitioning to `valid` with no prior `redeemed_at` triggers the
    reward handler automatically.
  - **Anti-abuse detection.** New user's IP and device fingerprint are compared
    against the referrer's full device history (not only the latest session), so
    a referrer who logs out before redemption is still detected.
  - **Per-signal abuse policy**, each independently configurable to `block`,
    `flag`, or `ignore`: `on_same_ip`, `on_same_device`, `on_same_ip_and_device`.
  - **Client restriction.** `AUTH_REFERRAL_ALLOWED_CLIENTS` accepts `web`,
    `mobile`, or `both`. A request from a disallowed client type fails silently
    (200 response, nothing persisted).
  - **Pluggable reward handler.** Implement
    `ReferralRewardHandlerContract::handle(Referral $referral): void` and set
    the FQCN in config. If the handler throws, the referral reverts to `pending`
    for retry.
- **Three new events:** `ReferralCreated`, `ReferralRedeemed`,
  `SuspiciousReferralDetected` (carries a `$reason` string).
- **New translation keys.**
  - Errors: `referral_code_not_found`, `referral_self_referral`,
    `referral_already_redeemed`, `referral_window_expired`,
    `referral_blocked_same_device`, `referral_blocked_same_ip`,
    `referral_blocked`, `referral_status_invalid`, `referral_not_found`,
    `device_not_found`.
  - Messages: `referral_redeemed`, `referrals_retrieved`,
    `referral_stats_retrieved`, `referral_status_updated`, `devices_retrieved`,
    `device_forgotten`.
- **Documentation.** `docs/referral-codes.md` — 15-section guide covering
  all referral flows, anti-abuse scenarios, browser and mobile fingerprint
  integration, reward handler examples, and admin override workflow.

### Migrations

| File | Creates / Alters |
|---|---|
| `2026_05_20_000001_create_referrals_table` | `referrals` table |
| `2026_05_20_000002_add_fingerprint_hash_to_auth_sessions_extended` | `fingerprint_hash` column on `auth_sessions_extended` |
| `2026_05_20_000003_create_auth_user_devices_table` | `auth_user_devices` table |

---

## [2.4.7] — 2026-05-20

### Added

- **Orphaned session cookie cleanup.** `POST /auth/session/destroy-orphan` is
  an unauthenticated endpoint for SPAs to call when `/auth/me` returns 401 but a
  stale session cookie is still present (e.g. after a manual database wipe or
  violated lifecycle). Forces the cookie to expire without requiring a valid token.

---

## [2.4.6] — 2026-05-19

### Fixed

- `POST /auth/email/resend-verification` returned an incorrect response body
  when the user's existing OTP had already expired. Now correctly returns the
  `verification_resent` message in all code paths.

---

## [2.4.5] — 2026-05-19

### Added

- **`EmailVerified` event.** Fired after email verification completes at the
  end of the registration flow. Listeners can use this event to trigger
  post-verification workflows (welcome emails, onboarding jobs, etc.).

---

## [2.4.3] — 2026-05-18

### Changed

- **Complete documentation rewrite.** All files under `docs/` were rewritten
  from scratch with full detail: installation walkthrough, configuration
  reference for every key, customization guide for all six contracts, events
  reference, localization guide, account status and deletion guides, and
  upgrading notes. `docs/AI_Context.md` added as a full repo snapshot for
  AI assistants.
- **`docs/` excluded from Composer archive.** The `archive.exclude` block in
  `composer.json` now excludes `docs/`, `tests/`, and Postman collections so
  production installs do not include documentation files.

---

## [2.4.2] — 2026-05-18

### Fixed

- **MySQL strict mode migration error.** The `deleted_accounts` table migration
  declared `deleted_at` and `scheduled_purge_at` without a default value, which
  MySQL strict mode rejects with `SQLSTATE[22007]`. Both columns are now
  `->nullable()`.

---

## [2.4.1] — 2026-05-17

### Added

- **Configurable route prefix.** Package routes can now be mounted at any URL
  prefix. Set `AUTH_ROUTES_PREFIX=api/v1/auth` in `.env` or `routes.prefix` in
  `config/auth_system.php`. Previously hardcoded to `auth`.
- **Route auto-register toggle.** Set `AUTH_ROUTES_REGISTER=false` to disable
  automatic route mounting and include the route file manually inside your own
  `Route::group()`. Useful for host apps that wrap all routes in a versioned
  API group.

---

## [2.4.0] — 2026-05-17

> **Git tag:** `v2.4` (tagged without the `.0` patch suffix).

Account lifecycle: configurable status workflow, timed bans, self-service
deactivation, soft-delete with grace-period auto-restore, and a full admin
audit log.

### Added

- **Account status system.** Five built-in statuses (`active`, `suspended`,
  `disabled`, `deactivated`, `deleted`; extensible via config). Login is
  rejected when the status is in `account.status.login_blocked`. The new
  `auth.active` middleware enforces the status on every authenticated request
  so a mid-session ban takes effect immediately.
- **Admin status endpoints.** `GET|POST /auth/admin/users/{id}/status`,
  gated by the role(s) in `account.status.admin_ability`. Status changes
  optionally revoke all of the user's Sanctum tokens and sessions.
- **Timed bans (auto-unban).** Admin endpoint accepts `expires_at` (ISO 8601)
  or `duration_minutes`. Unban fires via two mechanisms: lazy revert on every
  status read, and a scheduled sweep job every `auto_unban.sweep_minutes`
  minutes (default 5).
- **Self-service account deactivation.** Instagram-style pause via
  `POST /auth/account/deactivate`. Auto-reactivates on next login.
- **Account deletion with grace period.** `DELETE /auth/account` soft-deletes
  with a configurable grace window (default 30 days). Login within the window
  auto-restores the account. A purge worker nulls unique columns (auto-discovered
  via `Schema::getIndexes()`) and optionally hard-deletes the row after grace.
- **Account audit log.** Every status transition is written to
  `account_status_logs`. Admins can add free-form notes. History endpoint with
  pagination and filters at
  `GET /auth/admin/users/{id}/status/history` and
  `POST /auth/admin/users/{id}/notes`.
- **`HasAccountStatus` trait** — convenience methods `isActive()`,
  `isSuspended()`, `isDisabled()`, `isDeactivated()`, `isDeleted()` on the
  User model (optional).
- **Events:** `AccountStatusChanged`, `AccountDeleted`, `AccountRestored`,
  `AccountPurged`.
- **Notifications:** `AccountDeletedNotification`, `AccountRestoredNotification`,
  `AccountPurgedNotification`, `AccountStatusChangedNotification`,
  `AccountDeactivatedNotification`, `AccountReactivatedNotification`. All have
  publishable Blade views and FQCN config overrides.
- **New translation keys.** `account_disabled`, `account_suspended`,
  `account_deletion_disabled`, `account_deactivation_disabled`,
  `account_status_invalid`, `account_password_mismatch`, `account_deleted`,
  `account_restored`, `account_status_updated`, `account_deactivated`,
  `account_reactivated`.
- **Documentation.** `docs/account-status.md`, `docs/account-deletion.md`.

### Changed

- Login flow now includes soft-deleted users in the email lookup when the User
  model uses `SoftDeletes`, enabling auto-restore on credential match within the
  grace window.
- `InstallCommand` next-steps output now recommends adding the `SoftDeletes`
  trait to the host User model.

### Migrations

- `add_account_status_to_users_table` — adds `account_status`,
  `status_changed_at`, `status_reason`, `status_expires_at`, `deleted_at`.
- `create_deleted_accounts_table` — stores user snapshots during the grace
  period.
- `create_account_status_logs_table` — audit trail for status transitions.

---

## [2.3.2] — 2026-05-16

### Fixed

- `POST /auth/email/resend-verification` did not create a new OTP record when
  the user's existing OTP had already expired, causing the resent email to
  contain an invalid code. The controller now forces a fresh OTP before sending.

---

## [2.3.1] — 2026-05-15

### Fixed

- **`InstallCommand` rewrite.** `php artisan auth:install` now runs steps in
  the correct dependency order, prints clear error messages when a required
  package is missing instead of throwing a cryptic exception, and is safe to
  re-run on an already-installed app.
- **`AuthRolesSeeder` pre-flight.** The seeder now checks for the `roles` table
  before running and prints a helpful hint (run migrations first) instead of
  crashing with a raw SQL error.
- Minor fix to device column handling in `AuthSessionExtended`.
- `docs/installation.md` added.

---

## [2.3.0] — 2026-05-15

Customisation and localization pass. Every user-facing string the package
returns flows through Laravel's translation system. Three opt-in registration
customisation features added.

### Added

- **Multi-language support.** Every controller response message and every
  exception message now resolves via a three-step pipeline:
  1. `config('auth_system.messages.<key>')` / `config('auth_system.errors.<key>')` — static per-key override.
  2. `trans('auth_system::<file>.<key>')` — per-locale translation file, respects `app()->getLocale()`.
  3. Built-in English hardcoded fallback.
  English and Arabic language files ship with the package. Publish with
  `php artisan vendor:publish --tag=auth-lang`.
- **`config('auth_system.errors')` block** — 26 keys for static, locale-independent
  error message overrides.
- **Extra-field validation messages.** `registration.extra_fields_messages` —
  standard Laravel `field.rule => message` map for `extra_fields_rules` without
  requiring a custom `FormRequest` subclass.
- **Extra-field transformers.** `registration.extra_fields_transformers` — maps
  a target field name to a class implementing `ExtraFieldTransformerContract`.
  Runs post-validation, pre-persist. Useful for derivation
  (`username_normalized = strtolower(username)`) without writing a controller.
- **Referral code generation.** `referral_code` config block. When
  `auth_system.referral_code.enabled=true`, the package generates a unique
  referral code per new user during `finalizeRegistration()` and writes it to
  the configured column (default `referral_code`). Swappable generator via
  `ReferralCodeGeneratorContract`.
- **`AuthException` carries `errorKey` + `replacements`.** Exception subtypes
  now expose `errorKey()` and `errorReplacements()` for the translation pipeline.
  Placeholder syntax: standard Laravel `:name` (e.g. `:provider`, `:seconds`).

### Breaking change

`AuthException` and all subtype constructors changed:

```php
// Before (v2.1.x)
new AuthException(string $message, int $code = 0, ?Throwable $previous = null)

// After (v2.3.0)
new AuthException(string $message, ?string $errorKey = null, array $replacements = [], ?Throwable $previous = null)
```

Only affects code that **instantiates** package exceptions directly. Catching
them is unaffected — `$e->getMessage()` still works.

---

## [2.1.1] — 2026-05-15

### Fixed

- `ApiTokenAuth` middleware did not reject tokens that had been revoked in
  `auth_api_tokens` when the underlying Sanctum token still existed. The
  middleware now checks the `auth_api_tokens` revocation status directly before
  allowing the request through.
- Updated Postman collection to include all API token endpoints.

---

## [2.1.0] — 2026-05-15

### Fixed

- `GET /auth/register/verify-magic/{token}` was registered with the wrong HTTP
  method and returned `405 Method Not Allowed`. Fixed.
- `PasswordResetController` did not correctly handle a signed URL that had
  already been consumed. Now returns a clean `422` instead of a `500`.
- `EmailVerificationController::resend()` did not return a response when the
  user was already verified. Now returns `200` with the `verification_resent`
  message.

---

## [2.0.0] — 2026-05-09

Security hardening pass. Several breaking changes — review carefully before
upgrading.

### Breaking

- **3-step registration.** Passwords are no longer accepted in
  `POST /auth/register` and are no longer cached before email verification.
  This eliminates the pre-account takeover attack vector present in v1.x.

  **Old flow (v1.x):**
  ```
  POST /auth/register   { email, password }  → send OTP/magic
  POST /auth/register/verify-otp { email, otp } → create user
  ```

  **New flow (v2.0):**
  ```
  POST /auth/register            { email }
  POST /auth/register/verify-otp { email, otp }    → completion_token
  POST /auth/register/complete   { completion_token, password }
  ```

- **Refresh tokens moved to `auth_refresh_tokens`.** Atomic rotation with
  one-time use. Existing v1.x refresh tokens are invalid — users must log in
  again. Run `php artisan migrate`.
- **OTP codes stored as SHA-256 hashes.** Existing plaintext OTP records from
  v1.x will not match any hash lookup. Clear `auth_otp_codes` before upgrading.
- **`EmailVerified` event** — `sanctumToken` parameter removed.
  Remove any `$event->sanctumToken` usage from your listeners.
- **`SocialAuthService::redirectUrl`** now requires `Request $request` as a
  second argument.
- **`SocialAuthService::handleCallback`** now returns an array with a `status`
  key (`'logged_in'` or `'requires_link_confirmation'`). Check the `status`
  key before reading `user` / `token`.
- **Social account auto-linking by email removed.** Matching email alone is
  insufficient proof of ownership. The callback now returns `202` +
  `requires_link_confirmation` and emails a signed confirmation link. Handle
  the new `GET /auth/social/{provider}/link/confirm/{token}` in your frontend.
- **Magic-link endpoints** now 302-redirect to the configured
  `frontend_verify_url` / `frontend_reset_url` (with token in the query string)
  when the request comes from a browser. Set these env vars to keep the
  click-through flow working.
- `AuthService::logoutAll(User $user)` now requires `Request $request` as a
  second argument.
- `ConditionalCsrf` no longer exempts requests based on `X-Client-Type`.
  Only Bearer-token requests bypass CSRF.
- Minimum supported Laravel raised to `^12.0`.

### Security fixes

- **OTP brute-force defense.** Atomically increments `failed_attempts` on each
  wrong submission and invalidates the OTP after `otp_max_attempts` (default 5).
- **Refresh-token reuse detection.** Family-based rotation: a consumed token
  presented again revokes the entire family, logging out both attacker and
  legitimate client.
- **Rate limiter no longer auto-clears on 2xx.** In v1 this defeated the limiter
  on always-200 endpoints (forgot-password, resend-verification).
- **`logoutAll` preserves the calling token/session** so the response is not
  401'd before it returns.
- **Mass-assignment denylist.** `extra_fields` are stripped of `role`, `roles`,
  `is_admin`, `admin`, `email_verified_at`, `password`, and
  `password_change_required` before `User::create()`.
- **`finalizeRegistration` wrapped in a DB transaction.**
- **Constant-time `forgotPassword`** for unknown emails (performs a real
  `Hash::make` to prevent timing-based email enumeration).

---

## [1.0.1] — 2026-05-08

### Fixed

Six bugs found during integration testing:

- `AuthServiceProvider` did not correctly register package routes when the host
  app had custom route caching.
- Missing `use` imports in two controller classes caused `500` errors in PHP 8.3
  strict mode.
- `OtpService::create()` did not clean up expired records before inserting a new
  one, causing unique constraint violations on high-traffic apps.
- `TokenService::issueRefreshToken()` returned `null` on first-time logins.
- `SessionService` did not handle a missing `jenssegers/agent` gracefully.
  Now falls back to the raw User-Agent string.
- `AuthRolesSeeder` threw when the `roles` table did not exist. Now prints a
  clear error with instructions.

---

## [1.0.0] — 2026-05-08

Initial public release.

### Added

**Core authentication**
- `POST /auth/register` — initiates registration, sends OTP + magic link
  simultaneously, returns `temp_token`.
- `POST /auth/register/verify-otp` — completes registration via OTP code.
- `GET /auth/register/verify-magic/{token}` — completes registration via
  signed magic link.
- `POST /auth/email/resend-verification` — resends OTP/magic link
  (email-enumeration-safe).
- `POST /auth/login` — authenticates verified users, issues Sanctum Bearer
  token or session cookie depending on `AUTH_MODE`.
- `POST /auth/logout` — revokes current token/session.
- `POST /auth/logout/all` — revokes all sessions across all devices.
- `GET /auth/me` — returns user profile, roles, permissions, and active session
  count.

**Password management**
- `POST /auth/password/forgot` — sends OTP + magic link for password reset
  (email-enumeration-safe).
- `POST /auth/password/reset/otp` — validates OTP, issues `reset_token`.
- `GET /auth/password/reset/magic/{token}` — validates signed URL, issues
  `reset_token`.
- `POST /auth/password/reset/confirm` — sets new password using `reset_token`,
  revokes all existing sessions.
- `POST /auth/password/change` — changes password for authenticated user.

**Session & device tracking**
- `GET /auth/sessions` — lists all active sessions with device, browser, OS,
  IP, and geo info.
- `DELETE /auth/sessions/{id}` — revokes a specific session by ID.
- Device detection from `User-Agent` (web) and `X-Device-Info` header (mobile).
- ~500-model device lookup via `resources/devices.json`.

**API token system**
- `GET|POST|DELETE /auth/api-tokens` — user-scoped token management.
- `GET|POST|PATCH|DELETE /auth/admin/api-tokens` — admin token management.
- `ApiTokenAuth` middleware with per-ability checks.

**Google OAuth**
- `GET /auth/social/google/redirect` — returns Google authorization URL.
- `GET /auth/social/google/callback` — handles OAuth exchange; creates, links,
  or logs in user.

**Real-time verification via Reverb**
- Broadcasts `EmailVerified` on `auth.verification.{temp_token}` private channel
  when Reverb is enabled.

**Security**
- Dual-layer rate limiting per-IP and per-email on all public endpoints.
- Account lockout with configurable threshold and decay window.
- New-device detection: `SuspiciousLoginDetected` event + `NewDeviceLoginNotification`.
- `RequireEmailVerified` and `DeviceFingerprint` middleware.

**Infrastructure**
- `AuthServiceProvider` with Laravel auto-discovery.
- `php artisan auth:install` — publishes config, migrations, seeder, channel stub.
- `AuthRolesSeeder` — creates `super-admin`, `admin`, `user` roles.
- `CleanExpiredOtpRecords` (every 5 min) and `CleanExpiredApiTokens` (every hour).
- Customisable response envelope via `ResponseFormatterContract`.
- Customisable OTP delivery via `OtpChannelContract`.
- Full Pest test suite with `Mail::fake()` and `Queue::fake()`.
