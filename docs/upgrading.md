# Upgrading Guide

Version history for `joe-404/laravel-auth`. Every release is documented — what was added, what was fixed, and whether the version should be used in production.

> **Outdated versions** are marked with a warning. Do not pin to them. They contain known bugs and missing security patches. Always use the latest patch release in the current minor series.

---

## Table of Contents

- [v2.5.0 — Current stable](#v250----current-stable)
- [v2.4.2](#v242)
- [v2.4.1](#v241)
- [v2.4.0](#v240)
- [v2.3.2 ⚠ Outdated](#v232--outdated)
- [v2.3.1 ⚠ Outdated](#v231--outdated)
- [v2.3.0 ⚠ Outdated](#v230--outdated)
- [v2.1.1 ⚠ Outdated](#v211--outdated)
- [v2.1.0 ⚠ Outdated](#v210--outdated)
- [v2.0.0 ⚠ Outdated](#v200--outdated)
- [v1.0.1 ⚠ Outdated](#v101--outdated)
- [v1.0.0 ⚠ Outdated](#v100--outdated)

---

## Upgrading steps

### Upgrading to v2.5.0 from v2.4.x

**No breaking changes.** Run migrations, publish the updated config to pick up the new `referral_code` and `device` keys.

```bash
composer require joe-404/laravel-auth:^2.5
php artisan migrate
php artisan vendor:publish --tag=auth-config --force
```

**New `.env` variables (all optional — safe to ignore if features not needed):**

```env
# Referral codes
AUTH_REFERRAL_CODE_ENABLED=false
AUTH_REFERRAL_CODE_LENGTH=10
AUTH_REFERRAL_CODE_UPPERCASE=true
AUTH_REFERRAL_REDEEM_WINDOW=120
AUTH_REFERRAL_ALLOWED_CLIENTS=both
AUTH_REFERRAL_ABUSE_SAME_IP=flag
AUTH_REFERRAL_ABUSE_SAME_DEVICE=block
AUTH_REFERRAL_ABUSE_BOTH=block
```

**New tables created by migrations:**
- `referrals` — referral relationship, status, fingerprint snapshots, abuse flags
- `auth_user_devices` — permanent per-user device history (survives logout)
- `fingerprint_hash` column added to `auth_sessions_extended`

---

### Upgrading to v2.4.x from v2.3.x

**No breaking changes for most apps.** Run migrations and publish the updated config.

```bash
composer require joe-404/laravel-auth:^2.4
php artisan migrate
php artisan vendor:publish --tag=auth-config --force   # pick up new account.* keys
```

**Recommended User model changes:**

```php
use Illuminate\Database\Eloquent\SoftDeletes;
use Joe404\LaravelAuth\Concerns\HasAccountStatus;

class User extends Authenticatable
{
    use HasApiTokens, HasRoles, Notifiable, SoftDeletes, HasAccountStatus;
}
```

`SoftDeletes` is required if you want account deletion auto-restore to work. `HasAccountStatus` is optional sugar.

**New schema columns** added to `users`:
- `account_status` (varchar 32, default `active`)
- `status_changed_at` (timestamp, nullable)
- `status_reason` (text, nullable)
- `status_expires_at` (timestamp, nullable)
- `deleted_at` (timestamp, nullable — SoftDeletes column)

**New table:** `account_status_logs` — stores the audit trail for status changes and admin notes.

**New table:** `deleted_accounts` — stores snapshots of deleted user rows during grace.

---

### Upgrading to v2.3.x from v2.1.x

**One breaking change if you instantiate package exceptions directly.**

The constructor signature of `AuthException` and all subtypes changed:

```php
// Before (v2.1.x)
new OtpInvalidException(string $message, int $code = 0, ?Throwable $previous = null);

// After (v2.3.x)
new OtpInvalidException(string $message, ?string $errorKey = null, array $replacements = [], ?Throwable $previous = null);
```

If you only **catch** package exceptions (which is the normal case), no change needed. `$e->getMessage()` still works.

If you **throw** package exceptions in your own code (unusual), update the constructor arguments.

---

### Upgrading to v2.0.x from v1.x

**Multiple breaking changes.** See the v2.0.0 section below for the full list.

---

## v2.5.0 — Current stable

**Tag:** `v2.5.0` | **Released:** 2026-05-20

### Added

- **Permanent device history.** Every successful login writes a record to the new `auth_user_devices` table. The record survives logout and session revocation — giving users a full audit trail of every device that has ever accessed their account. Exposed via `GET /auth/devices` (list) and `DELETE /auth/devices/{id}` (forget device + revoke any active sessions with that fingerprint).

- **Browser and mobile device fingerprinting.** `DeviceService` now extracts a `fingerprint_hash` from the `X-Browser-Fingerprint` header (browser/SPA) or the `device_id` field in the `X-Device-Info` header (mobile). The hash is stored on both `auth_sessions_extended` and `auth_user_devices` for abuse detection. A `device_signature` is derived by priority: fingerprint hash → device code SHA-256 → browser+OS+platform SHA-256; this de-duplicates records across reinstalls and browser-clears on the same physical device.

- **Referral code system.** Config-driven, pluggable referral system with the following capabilities:
  - Auto-generate referral codes on registration (configurable length, uppercase toggle, custom generator via `ReferralCodeGeneratorContract`).
  - Submit a referral code after registration via `POST /auth/referrals/redeem`. Code must be redeemed within a configurable time window (`AUTH_REFERRAL_REDEEM_WINDOW`).
  - Abuse detection: compares the new user's IP and device fingerprint against the **full device history** of the referrer (not just the latest session). Even if the referrer logs out before the referral is submitted, the history is still checked.
  - Per-signal abuse policy (each independently configurable to `block`, `flag`, or `ignore`): `on_same_ip`, `on_same_device`, `on_same_ip_and_device`.
  - Client restriction: restrict redemption to `web`, `mobile`, or `both` via `AUTH_REFERRAL_ALLOWED_CLIENTS`. Wrong client type fails silently — 200 response, nothing persisted.
  - Pluggable reward handler: implement `ReferralRewardHandlerContract::handle(Referral $referral): void` and bind it in config. If the handler throws, the referral reverts to `pending` for retry.
  - Admin override: `PATCH /auth/admin/referrals/{id}` — change status and add a note. Transitioning to `valid` with no prior `redeemed_at` triggers the reward handler automatically.
  - Referral endpoints: `GET /auth/referrals`, `GET /auth/referrals/stats`, `GET /auth/admin/referrals`, `PATCH /auth/admin/referrals/{id}`.

- **Three new events:** `ReferralCreated` (on every submission), `SuspiciousReferralDetected` (carries `$reason` string), `ReferralRedeemed` (fires after reward handler succeeds).

### New tables

| Migration | Table / Column |
|---|---|
| `2026_05_20_000001` | `referrals` |
| `2026_05_20_000002` | `fingerprint_hash` column on `auth_sessions_extended` |
| `2026_05_20_000003` | `auth_user_devices` |

---

## v2.4.2

**Tag:** `v2.4.2` | **Released:** 2026-05-17

### Fixed

- **MySQL strict mode migration error.** The `deleted_accounts` table migration declared two timestamp columns without a default value, which MySQL strict mode rejects with `SQLSTATE[22007]`. Both `deleted_at` and `scheduled_purge_at` are now `->nullable()`. This was a silent fail on many MySQL setups.

### Who must upgrade

Anyone running MySQL in strict mode (`sql_mode` includes `STRICT_TRANS_TABLES`) on v2.4.0 or v2.4.1. The migration failed silently on some setups and ran but produced incorrect schema on others.

---

## v2.4.1

**Tag:** `v2.4.1` | **Released:** 2026-05-16

### Added

- **Configurable route prefix.** The package routes can now be mounted at any URL prefix, not just `/auth`. Set `AUTH_ROUTES_PREFIX=api/v1/auth` in `.env` or `routes.prefix` in `config/auth_system.php`.
- **Route auto-register toggle.** Set `AUTH_ROUTES_REGISTER=false` to disable automatic route mounting and include the route file manually inside your own `Route::group()`.

### Why these were added

Host applications that mount all routes under a versioned API group (`/api/v1/...`) could not use the package's auto-mount without URL conflicts. The prefix and manual-mount options solve this without forking.

### Upgrading from v2.4.0

No breaking changes. Run `php artisan vendor:publish --tag=auth-config --force` to pick up the new `routes` config keys. If you don't publish, defaults apply (`prefix=auth`, `register=true`) and behaviour is unchanged.

---

## v2.4.0

**Tag:** `v2.4` | **Released:** 2026-05-16

### Added

- **Account status system.** Five built-in statuses (`active`, `suspended`, `disabled`, `deactivated`, `deleted`) with configurable login blocking and session revocation.
- **`auth.active` middleware.** Immediately rejects requests from users whose status changed after login. Register on any route group to enforce mid-session bans.
- **Timed bans.** Admin endpoint accepts `expires_at` (ISO 8601) or `duration_minutes`. Auto-unban fires via two mechanisms: lazy revert on every status read, and a scheduled sweep job every 5 minutes.
- **Account deactivation.** Instagram-style self-service pause via `POST /auth/account/deactivate`. Auto-reactivates on next login.
- **Account deletion with grace period.** `DELETE /auth/account` soft-deletes with a 30-day grace window. Login within grace auto-restores. A purge worker nulls unique columns (and optionally hard-deletes the row) after grace.
- **Account audit log.** Every status transition is written to `account_status_logs`. Admins can add free-form notes. History endpoint with pagination and filters.
- **Six new events:** `AccountStatusChanged`, `AccountDeleted`, `AccountRestored`, `AccountPurged`, and two previously unlisted (`AccountDeactivated` is not a separate event — it fires `AccountStatusChanged`).
- **Six new notification classes** for account lifecycle emails, all with Blade view and custom-class override support.
- **`HasAccountStatus` concern** — convenience trait adding `isActive()`, `isSuspended()`, `isDisabled()`, `isBanned()`, `isDeactivated()`, `isDeleted()` methods to the User model.

### Known issue (fixed in v2.4.2)

The `deleted_accounts` migration fails on MySQL strict mode. Upgrade to v2.5.0.

---

## v2.3.2 ⚠ Outdated

> **Do not use.** Superseded by v2.4.x. This version is missing the entire account status system and all account lifecycle features introduced in v2.4.

**Tag:** `v2.3.2`

### Fixed

- Resend verification email did not correctly re-send when the user's OTP had expired. The `EmailVerificationController` now forces a new OTP record to be created on resend.

---

## v2.3.1 ⚠ Outdated

> **Do not use.** Superseded by v2.4.x. Contains the resend-verification bug fixed in v2.3.2.

**Tag:** `v2.3.1`

### Added / Fixed

- Complete rewrite of the `InstallCommand` (`php artisan auth:install`). Now runs steps in the correct order, prints clear error messages when a dependency is missing instead of throwing a cryptic exception, and is safe to re-run.
- `AuthRolesSeeder` now pre-flights the `roles` table existence and prints a helpful hint instead of crashing with a raw SQL error.
- `AuthSessionExtended` model — minor fix to device column handling.
- `docs/installation.md` added.

---

## v2.3.0 ⚠ Outdated

> **Do not use.** Contains a broken `InstallCommand` (fixed in v2.3.1) and the resend-verification bug (fixed in v2.3.2). Upgrade to v2.5.0.

**Tag:** `v2.3.0`

### Added

- **Multi-language support.** Every user-facing string now flows through a three-step resolver: config static override → translation file → built-in English fallback. Ships with English and Arabic out of the box.
- **`php artisan vendor:publish --tag=auth-lang`** — publish and edit the language files.
- **`config('auth_system.errors')` block** — 26 per-key static overrides for error messages. Previously only success messages had overrides.
- **`extra_fields_messages`** — custom validation messages for extra registration fields without writing a custom FormRequest subclass.
- **`extra_fields_transformers`** — derive a target column from validated registration input (e.g. `username` → `username_normalized`) without writing a controller.
- **`referral_code` config block** — auto-generate and store unique referral codes per user at registration.
- **`ReferralCodeGeneratorContract`** — swap the referral code generation logic.
- **`ExtraFieldTransformerContract`** — the contract for field transformers.

### Breaking change

`AuthException` and all subtype constructors changed:

```php
// Before
new AuthException(string $message, int $code = 0, ?Throwable $previous = null)

// After
new AuthException(string $message, ?string $errorKey = null, array $replacements = [], ?Throwable $previous = null)
```

Only affects code that **instantiates** package exceptions directly. Catching them is unaffected.

---

## v2.1.1 ⚠ Outdated

> **Do not use.** Missing all features introduced in v2.3.x and v2.4.x. Upgrade to v2.5.0.

**Tag:** `v2.1.1`

### Fixed

- `ApiTokenAuth` middleware did not reject tokens that had been revoked but whose associated Sanctum token still existed. Fixed — the middleware now checks the `auth_api_tokens` table revocation status directly.
- Updated Postman collection to include all API token endpoints.

---

## v2.1.0 ⚠ Outdated

> **Do not use.** Contains the `ApiTokenAuth` revocation bug fixed in v2.1.1. Missing all features from v2.3.x and v2.4.x. Upgrade to v2.5.0.

**Tag:** `v2.1.0`

### Fixed

- `GET /auth/register/verify-magic/{token}` (magic-link route) was registered with the wrong HTTP method and returned 405. Fixed.
- `PasswordResetController` did not correctly handle cases where the signed URL had already been consumed. Now returns a clean 422 instead of throwing a 500.
- `EmailVerificationController::resend()` did not return a response when the user was already verified. Fixed — returns 200 with the existing `verification_resent` message.

---

## v2.0.0 ⚠ Outdated

> **Do not use in production.** This version has known issues and is missing all features added in v2.1.x through v2.4.x. Upgrade to v2.5.0.

**Tag:** `v2.0.0`

### Breaking changes from v1.x

#### 1. Registration is now 3 steps (was 2)

Passwords are no longer accepted in `POST /auth/register` and are no longer cached before email verification. This eliminates the pre-account takeover attack vector.

**Old flow (v1.x):**
```
POST /auth/register   { email, password }  → send OTP/magic
POST /auth/register/verify-otp { email, otp } → create user
```

**New flow (v2.0):**
```
POST /auth/register            { email }          → send OTP/magic, return temp_token
POST /auth/register/verify-otp { email, otp }     → return completion_token
POST /auth/register/complete   { completion_token, password } → create user
```

**Frontend changes required:**
- Remove `password` and `password_confirmation` from the initial POST body
- Store the `completion_token` from the verify response
- Add a "Set your password" step that POSTs to `/auth/register/complete`

#### 2. Refresh tokens moved to dedicated table

Refresh tokens are now stored in `auth_refresh_tokens` (atomic rotation, one-time use). Previously they were stored in `personal_access_tokens`.

Existing v1.x refresh tokens are not valid in v2.0. Users must log in again to receive a new refresh token.

**Migration required:**
```bash
php artisan migrate   # creates auth_refresh_tokens table
```

#### 3. OTP codes stored as SHA-256 hashes

`auth_otp_codes.token` now stores a SHA-256 hash instead of plaintext. Any existing OTP records from v1.x must be cleared — they will not match any hash lookup.

#### 4. `EmailVerified` event — `sanctumToken` parameter removed

```php
// Before (v1.x)
class EmailVerified {
    public function __construct(User $user, string $tempToken, ?string $sanctumToken = null) {}
}

// After (v2.0)
class EmailVerified {
    public function __construct(User $user, string $tempToken) {}
}
```

Remove any usage of `$event->sanctumToken` from your listeners.

#### 5. `SocialAuthService::redirectUrl` signature changed

```php
// Before
public function redirectUrl(string $provider): string

// After
public function redirectUrl(string $provider, Request $request): string
```

#### 6. `SocialAuthService::handleCallback` return type changed

Now returns an array with a `status` key (`'logged_in'` or `'requires_link_confirmation'`). If you called this method directly, check for the status key before reading `user` / `token`.

---

## v1.0.1 ⚠ Outdated

> **Do not use.** The 2-step registration flow in this version has a known pre-account takeover vulnerability (fixed in v2.0.0). Upgrade to v2.5.0.

**Tag:** `v1.0.1`

### Fixed

Six bugs found during integration testing:

- `AuthServiceProvider` did not correctly register the package routes when the host app had custom route caching.
- Missing `use` imports in two controller classes caused 500 errors in PHP 8.3 strict mode.
- `OtpService::create()` did not clean up expired records before inserting a new one, causing unique constraint violations on high-traffic apps.
- `TokenService::issueRefreshToken()` returned null on first-time logins. Fixed — now always creates a refresh token.
- `SessionService` did not handle missing `jenssegers/agent` gracefully. Now falls back to raw User-Agent string.
- `AuthRolesSeeder` threw when `roles` table did not exist. Now prints a helpful error.

---

## v1.0.0 ⚠ Outdated

> **Do not use.** The 2-step registration flow has a known pre-account takeover vulnerability (fixed in v2.0.0). Also contains all bugs fixed in v1.0.1. Upgrade to v2.5.0.

**Tag:** `v1.0.0`

### Initial public release

First version of the package. Included:
- 2-step email registration (OTP or magic link)
- Login with Sanctum token or session cookie
- Refresh token rotation
- Password reset (OTP or magic link)
- Password change (authenticated)
- Session tracking (device, browser, OS, IP, location)
- Google OAuth with safe account linking
- Long-lived API token system (scoped, expiry)
- Rate limiting per IP and per email
- Account lockout
- New device login email alerts
- Reverb WebSocket real-time verification (optional)
- Spatie Permission role auto-assignment on registration
