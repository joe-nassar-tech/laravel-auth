# Upgrading Guide

Version history for `joe-404/laravel-auth`. Every release is documented — what was added, what was fixed, and whether the version should be used in production.

> **Outdated versions** are marked with a warning. Do not pin to them. They contain known bugs and missing security patches. Always use the latest patch release in the current minor series.

---

## Table of Contents

- [v2.6.0 — Current stable](#v260----current-stable)
- [v2.5.1](#v251)
- [v2.5.0](#v250)
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

### Upgrading to v2.6.0 from v2.5.x

v2.6 is **additive** — existing users without 2FA enrolled see no flow changes, no API breakage. The upgrade is one command plus a small User-model edit.

#### 1. Composer update

```bash
composer update joe-404/laravel-auth
```

Two new dependencies are pulled in automatically:

- `pragmarx/google2fa: ^8.0` — RFC 6238 TOTP implementation
- `bacon/bacon-qr-code: ^3.0` — QR-code SVG generator for authenticator-app enrollment

#### 2. Run the upgrade migrations

```bash
php artisan auth:install --upgrade
```

This runs only the new `2025_v260_*` migrations (it skips re-publishing Sanctum/Spatie migrations) and prints a feature summary. New tables:

- `auth_two_factor_methods`
- `auth_two_factor_backup_codes`
- `auth_two_factor_challenges`
- `auth_trusted_devices`
- `auth_phone_otp_codes`

New columns on `users`: `phone`, `phone_verified_at`, `two_factor_required`.

#### 3. Update your User model

```php
protected $fillable = [
    // …existing…
    'phone', 'phone_verified_at', 'two_factor_required',
];

protected $casts = [
    // …existing…
    'phone_verified_at'   => 'datetime',
    'two_factor_required' => 'bool',
];
```

#### 4. (Optional) Re-publish config to see the new sections

```bash
php artisan vendor:publish --tag=auth-config --force
```

Three new sections appear — `phone`, `two_factor`, `trusted_devices`. All v2.6 features are **disabled or non-intrusive by default**; your app behaves exactly as on v2.5 until you opt in.

#### 5. Enable what you want

**Phone capture + verification.** Default driver is `log` (writes codes to the Laravel log — dev only). In production set a real provider:

```env
AUTH_PHONE_ENABLED=true
AUTH_PHONE_REQUIRED=false              # nullable at registration
AUTH_PHONE_VERIFY_AT_REG=false         # verify later, not at register
AUTH_PHONE_SMS_PROVIDER=infobip
INFOBIP_API_KEY=...
INFOBIP_BASE_URL=https://api.infobip.com
```

Built-in providers: `log`, `infobip`, `messagecentral`, `twilio`, `firebase`. Custom providers register via `PhoneDriverManager::extend()` — see `docs/customization.md`.

**Two-factor authentication.**

```env
AUTH_2FA_ENABLED=true                  # on by default
AUTH_2FA_REQUIRED=false                # per-user opt-in, not forced
AUTH_2FA_DEFAULT=totp                  # totp | email | sms
AUTH_2FA_MIDDLEWARE=password_confirm   # block | force_enroll | password_confirm
```

Enrollment (post-login): `POST /auth/2fa/enroll/totp/start` → scan QR → `POST /auth/2fa/enroll/totp/verify`. The first method's verify response returns `backup_codes` once.

Login becomes a two-step flow once 2FA is enrolled:

```http
POST /auth/login        → { requires_2fa, challenge_token, available_methods }
POST /auth/2fa/challenge → { token, refresh_token, trusted_device_token? }
```

**Trusted devices.**

```env
AUTH_TRUSTED_DEVICES_ENABLED=true
AUTH_TRUST_BYPASS_MIN=high             # devices >= high skip the 2FA challenge
AUTH_TRUST_LEVEL_MODE=time             # time | time_consistent | time_admin
```

**Trusted-device 2FA bypass requires two signals — not fingerprint alone.** When a device is trusted, the package issues a one-time `trusted_device_token` (returned in the registration response and in `/auth/2fa/challenge` when `trust_device=true`). The client must send it back as the **`X-Trusted-Device-Token`** header — together with `X-Browser-Fingerprint` — for the bypass to apply. Fingerprint is client-controlled and never bypasses on its own. Store the token in mobile Keychain or an HttpOnly cookie; it is returned exactly once.

**Social sign-in when you require custom fields.** OAuth (Google) gives you the user's email + name but never your app's required fields (username, phone, country…). Enable profile completion so a brand-new social user is asked for those fields before the account is created:

```env
AUTH_SOCIAL_PROFILE_COMPLETION=true      # default false (legacy: create + log in immediately)
AUTH_SOCIAL_PROFILE_COMPLETION_TTL=15    # minutes the completion token is valid
```

With it on, the callback for a brand-new user returns a completion step instead of a token:

```http
GET /auth/social/google/callback
  → 202 { requires_profile_completion: true, completion_token, prefill: { email, name, avatar } }

POST /auth/social/complete   { completion_token, username, phone, … }
  → validates the SAME registration.extra_fields_rules + phone rules as the email flow
  → creates the user, links the social account, issues the real token
```

No user row is created until `/auth/social/complete` succeeds — an abandoned onboarding leaves nothing behind, exactly like the 3-step email flow. Only `required` fields block; optional ones can be filled later. Existing users (and all users when the flag is off) keep logging in directly.

#### 6. Protect sensitive endpoints with step-up (optional)

```php
Route::middleware(['auth:sanctum', 'auth.2fa'])->group(function () {
    Route::delete('billing/subscription', /* … */);
});
```

`auth.2fa` issues a fresh 2FA challenge (or password-confirm fallback for users without 2FA). See `docs/middleware.md` for the full middleware reference.

#### Behavior changes to be aware of

| Behavior | v2.5 | v2.6 |
|---|---|---|
| Login, 0 enrolled 2FA methods | issues token | **unchanged** |
| Login, ≥1 enrolled 2FA method | n/a | returns `challenge_token` first |
| Trusted device w/ fingerprint **+ token** ≥ bypass level | n/a | skips 2FA, issues token |
| New Google user, `profile_completion` off | creates + logs in from Google profile | **unchanged** |
| New Google user, `profile_completion` on | n/a | returns `requires_profile_completion`; account created only at `/auth/social/complete` |
| `UserLoggedIn` event | fires at login | still fires at credential success even when 2FA pending; new `TwoFactorChallengeIssued` + `TwoFactorVerified` events added |

#### Rolling back

```bash
php artisan migrate:rollback --step=7
composer require joe-404/laravel-auth:^2.5.1
```

---

### Upgrading to v2.5.1 from v2.5.0

**No breaking changes, no migrations.** This is a security and correctness pass on the refresh-token flow plus a handful of hardening fixes.

```bash
composer require joe-404/laravel-auth:^2.5
php artisan vendor:publish --tag=auth-config --force   # pick up new optional keys
```

**Behavior changes you should be aware of:**

1. **Refresh now re-checks account state.** A suspended, disabled, soft-deleted, or unverified user calling `POST /auth/refresh` will be rejected — they used to get a fresh token pair. Frontends should handle the same error responses they already handle on login.

2. **Refresh now keeps the session row in sync.** After rotation, `auth_sessions_extended.sanctum_token_id` is repointed at the new access token. Sessions listings, `DELETE /auth/sessions/{id}`, and last-active tracking now keep working past the first refresh.

3. **GeoIP lookup is now queued.** If you have `auth_system.device.resolve_location=true`, the country/city resolution is now dispatched as a `BackfillSessionLocation` job. The session row is created with `country`/`city` = null and the values fill in once the queue worker runs. **A queue worker must be running for the columns to populate.** The endpoint is now HTTPS (`https://ip-api.com/json/{ip}` by default).

4. **`X-Browser-Fingerprint` is now format-validated.** Values that are not hex digests within `[32, 128]` characters are silently ignored (treated as absent). If your frontend was sending something other than a hex digest, switch to one — e.g. SHA-256 of the canvas/WebGL/screen signals.

5. **`ApiTokenAuth` no longer echoes raw exception messages.** Clients calling endpoints behind `auth.api-token` will see `"Invalid API token."` for unknown errors instead of the underlying exception message. The original is logged via `Log::error`.

6. **`isAuthRoute()` now respects `AUTH_ROUTES_PREFIX`.** If you mounted the package under a custom prefix (e.g. `api/v1/auth`) on v2.5.0 or earlier, validation and authentication failures on those routes were not being wrapped in the package JSON envelope. They are now.

**New optional `.env` / config keys (safe to leave at defaults):**

```env
# When true (default), POST /auth/refresh rejects unverified users.
# Set false to keep legacy behavior (verification only enforced at login).
AUTH_VERIFICATION_REQUIRED_FOR_REFRESH=true
```

```php
// config/auth_system.php

'verification' => [
    'required_for_refresh' => env('AUTH_VERIFICATION_REQUIRED_FOR_REFRESH', true),
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

> The free `ip-api.com` plan only allows HTTP. If you are using the free plan, either override `device.location_endpoint` back to `http://ip-api.com/json/{ip}` (note: cleartext transport) or switch to a provider that supports HTTPS on the free tier.

---

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

## v2.6.0 — Current stable

**Tag:** `v2.6.0` | **Released:** 2026-05-25

Phone capture + verification, full two-factor authentication (TOTP / Email / SMS) with backup codes, and a trusted-device system with time-based trust levels. New `auth.2fa` step-up middleware. **Additive** — users without 2FA enrolled see no flow changes.

### Added

- **Phone number support** at registration (config-driven required/optional), with a pluggable driver system: `log` (dev), `infobip`, `messagecentral`, `twilio`, `firebase`, and custom drivers via `PhoneDriverContract` + `PhoneDriverManager::extend()`. Per-channel (sms/voice/whatsapp) provider selection with optional fallback driver.
- **Two-factor authentication** — TOTP (`pragmarx/google2fa`, server-rendered QR), Email OTP, and SMS OTP. Multiple methods enrollable in parallel; the user picks any at challenge time.
- **Backup codes** — 8 single-use codes generated on first 2FA enrollment, HMAC-SHA256 hashed with the app key as pepper, regeneratable.
- **Login challenge flow** — once 2FA is enrolled, login returns a `challenge_token` instead of a token; `POST /auth/2fa/challenge` completes it. Method switching + resend supported.
- **Trusted devices** — registration device auto-trusted; time-based progression (`low`/`medium`/`high`); three assignment modes; revocation matrix. **2FA bypass requires both the device fingerprint and a server-issued `X-Trusted-Device-Token`** — fingerprint alone never bypasses.
- **`Require2FA` middleware (`auth.2fa`)** — GitHub-style step-up for sensitive endpoints, with `block` / `force_enroll` / `password_confirm` fallbacks.
- **Social profile completion** — when `social.profile_completion.enabled` is true, a brand-new OAuth user missing the host's required fields gets a `requires_profile_completion` step (`POST /auth/social/complete`) instead of being created immediately, enforcing the same `extra_fields_rules` + phone rules as the email flow. No user row until completion.
- **Password sudo mode** — `POST /auth/password/confirm` grants a short step-up window for the `password_confirm` middleware path.
- **`Request::authContext()`** — read-only snapshot of `2fa_enabled`, `2fa_verified`, `trust_level`, `phone_verified`, `sudo_active`.
- **`auth:install --upgrade`** — runs only the new v2.6 migrations and prints a feature summary.
- **8 new events** — `PhoneVerified`, `TwoFactorEnrolled`, `TwoFactorDisabled`, `TwoFactorVerified`, `TwoFactorChallengeIssued`, `TwoFactorChallengeFailed`, `TrustedDeviceAdded`, `TrustedDeviceRevoked`.

### New tables

`auth_two_factor_methods`, `auth_two_factor_backup_codes`, `auth_two_factor_challenges`, `auth_trusted_devices`, `auth_phone_otp_codes`. New `users` columns: `phone`, `phone_verified_at`, `two_factor_required`.

### New dependencies

`pragmarx/google2fa: ^8.0`, `bacon/bacon-qr-code: ^3.0`.

### Changed

- `UserLoggedIn` still fires at credential success even when a 2FA challenge is pending (preserves v2.5 listener semantics). `TwoFactorChallengeIssued` fires when the challenge is created; `TwoFactorVerified` fires on completion.
- Default `trusted_devices.bypass_2fa_min_level` is `high` (the strongest trust signal) — override with `AUTH_TRUST_BYPASS_MIN=medium` for looser UX.

### Who must upgrade

Anyone who wants phone verification, 2FA, or trusted devices. Pure additive — safe for existing v2.5 deployments. See the [Upgrading to v2.6.0 from v2.5.x](#upgrading-to-v260-from-v25x) steps above.

---

## v2.5.1

**Tag:** `v2.5.1` | **Released:** 2026-05-22

### Fixed

- **Refresh now re-validates account status.** `TokenService::refresh()` used to mint new tokens without re-checking whether the user was still allowed to authenticate, so a suspended, disabled, or soft-deleted user could keep rotating tokens for the lifetime of the refresh window. The flow now re-runs `AccountStatusService::assertCanLogin()`, `hasVerifiedEmail()` (gated by the new `verification.required_for_refresh` key), and `trashed()` before issuing the new pair.
- **Refresh rotation is now properly atomic.** Reuse detection used to read the token row outside the rotation transaction, which let two concurrent legitimate refreshes both pass the `consumed` check and produced "Invalid refresh token" without the family revoke. The row is now `lockForUpdate`-selected inside the transaction; any presentation of a consumed token revokes the whole family (RFC 6749 §10.4 strict rotation). The revoke runs *after* the rotation transaction commits, so its writes cannot be rolled back by the throw that follows.
- **Refresh now updates the session record.** Previously, `AuthService::refreshToken()` rotated the Sanctum token but left `auth_sessions_extended.sanctum_token_id` pointing at the now-deleted old token, silently breaking `/auth/sessions`, session revocation, and last-active tracking after the first refresh. The session row is now re-pointed (or created if missing) and `last_active_at` is bumped.
- **Exception renderer honors the configured route prefix.** `AuthServiceProvider::isAuthRoute()` used to hardcode `auth/`, so hosts mounted under `api/v1/auth` lost the JSON envelope around `ValidationException` and `AuthenticationException`. It now reads `auth_system.routes.prefix`; for root-mounted setups it falls back to matching named routes that start with `auth.`.
- **Frontend magic-link URL is now validated.** Setting `magic_link_target=frontend` with an empty or malformed `frontend_verify_url` / `frontend_reset_url` used to produce emails with broken links (`?token=...` and no host). The package now throws `AuthConfigurationException` at link-generation time so misconfigurations fail loudly in staging instead of silently in production.
- **`ApiTokenAuth` no longer leaks raw exception messages.** Unknown exceptions during token validation used to be returned verbatim in the response body — exposing SQL fragments, file paths, or stack-trace hints. Known `AuthException` subclasses still pass through with their safe message; everything else is logged and replaced with a generic `"Invalid API token."`.
- **Browser fingerprint header is format-validated.** `X-Browser-Fingerprint` used to be accepted verbatim (truncated to 191 chars). It must now be a hex digest within `[browser_fingerprint_min_length, browser_fingerprint_max_length]` characters (defaults 32–128) — otherwise it is treated as absent. **The fingerprint is still advisory and must not be treated as proof of device identity.**
- **GeoIP lookup no longer blocks the auth path and uses HTTPS.** The third-party IP-to-location call (off by default) used to run synchronously over `http://` on every login, adding up to 3s of latency per request. It now runs as the new `BackfillSessionLocation` queued job, which fills `country`/`city` on the session row after it is created. The default endpoint is `https://ip-api.com/json/{ip}` and is overridable via `auth_system.device.location_endpoint`.
- **Removed an unused `use Mockery;`** statement in `tests/Feature/Auth/SocialAuthTest.php` that was producing a PHP warning on every test run (`Mockery` is in the root namespace, so the import had no effect).

### Added

- **`AuthConfigurationException`** — typed exception for programmer-facing misconfiguration. Default error key: `auth_misconfigured`.
- **`BackfillSessionLocation`** job — queues GeoIP lookups off the auth request path. Dispatched only when `device.resolve_location=true`, the IP is public, and the session row was not pre-populated by a host-app resolver.

### New optional config keys

| Key | Default | Purpose |
|---|---|---|
| `verification.required_for_refresh` | `true` | Whether `POST /auth/refresh` requires a verified email. Legacy behavior was `false` (verification only at login). |
| `referral_code.browser_fingerprint_min_length` | `32` | Minimum accepted length for `X-Browser-Fingerprint`. |
| `referral_code.browser_fingerprint_max_length` | `128` | Maximum accepted length for `X-Browser-Fingerprint`. |
| `device.location_endpoint` | `https://ip-api.com/json/{ip}` | URL template for the GeoIP lookup. `{ip}` is replaced. |
| `device.location_queue` | `default` | Queue name for `BackfillSessionLocation`. |

### Who must upgrade

Anyone on v2.5.0. The refresh-flow fixes (sessions, status re-check, atomic reuse detection) and the API-token error-leak fix are security-relevant.

---

## v2.5.0

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
