# Changelog

All notable changes to `joe-404/laravel-auth` are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/) and this project uses [Semantic Versioning](https://semver.org/).

---

## [2.7.1] — 2026-05-28

Audit-driven follow-up to v2.7.0. **Safe, non-breaking upgrade** — every
behavior change is behind a config flag defaulting to today's behavior.
See [UPGRADING.md](UPGRADING.md).

> Note on the v2.7.0 release: the published `v2.7.0` tag was placed on the
> v2.6.1 commit before the v2.7 work was merged, so `composer require
> joe-404/laravel-auth:^2.7.0` resolves to the v2.6.1 code. Upgrading to
> **`v2.7.1`** delivers the **real** v2.7 security pass **plus** the v2.7.1
> items below in one step.

### Security — fixed (applied automatically)

- **OTP + magic-link consumption is atomic.** Replaced the read-then-write
  consume with a conditional `UPDATE … WHERE used_at IS NULL` so two
  concurrent requests with the same valid code can never both succeed
  (security-review #1).
- **2FA challenge tokens are stored as HMAC at rest.** The DB only ever sees
  the digest; the plaintext is returned to the client exactly once at
  creation (security-review #5). Reuse semantics: the row is still de-
  duplicated, but the token is rotated on reuse (the latest call's token is
  the active one).
- **Trusted-device secret is HMAC-peppered** (security-review #6). Currently
  trusted devices are re-challenged once on upgrade.
- **API tokens record their creator** in new `created_by_*` columns — user-
  issued tokens self-attribute, admin-issued (unowned) tokens carry the
  admin's id (security-review #8 audit half).

### Security — added (opt-in, defaults preserve today's behavior)

- **`password_reset.auto_login`** — set `false` to force a fresh login after a
  reset; revokes every session and issues no token (security-review #4).
- **`api_tokens.admin_require_step_up`** — admin token creation can require a
  fresh sudo / 2FA step-up, mirroring the user-side flag.
- **`AdminGate` middleware** replaces the hard-coded `role:` on package admin
  route groups. Configurable via `account.status.admin_middleware` and
  `api_tokens.admin_middleware` — accepts any pipe-separated list of roles
  and/or Spatie permissions (security-review #7).
- **`response.hidden_user_fields`** — the always-stripped serialization list
  is now config, so hosts can add custom sensitive columns without losing
  the defensive net.
- **`security.profile`** preset (`relaxed` | `balanced` | `high`) — fills in
  safe defaults at boot, but only for keys whose env var is unset, so any
  explicit `.env` value still wins. `high` flips on every hardening flag the
  library exposes.

### Changed

- CI workflow now runs `composer audit` as a release-gate check.

---

## [2.7.0] — 2026-05-27

Second security-hardening pass (audit-driven). **Safe, non-breaking upgrade**:
every behavior change is behind a config flag that defaults to the current
behavior. See [UPGRADING.md](UPGRADING.md) for the flag-by-flag guide and the
recommended production profile.

### Security — fixed (applied automatically)

- **Email-based 2FA now works on strict databases.** `auth_otp_codes.type` was
  an `enum` that never included the 2FA purposes (`two_factor_email`,
  `two_factor_email_enroll`), so storing an email-2FA code was rejected on
  strict MySQL / PostgreSQL / SQLite — the method was effectively broken. The
  column is widened to `string(40)`.
- **Login no longer leaks account existence by timing.** A login for a
  non-existent email now performs the same bcrypt work as a wrong-password
  login (mirrors the existing constant-time path in forgot-password).
- **Token-refresh no longer risks leaking the password hash.** The refresh
  response now goes through the same `safeUserArray()` net (strips `password`
  / `remember_token`) as every other endpoint, instead of raw `toArray()`.
- **TOTP codes cannot be replayed** within their validity window — the verified
  RFC 6238 time-step is recorded and re-use is rejected.
- **Step-up cache keys are UUID/string-PK safe.** `Require2FA` cast the user key
  to `int` while the writer used the raw key, silently breaking step-up for
  non-integer primary keys; all step-up keys now use the raw key.
- **`POST /auth/2fa/challenge/switch` is rate-limited** (it delivers a code),
  and **`POST /auth/password/confirm` is throttled per-user** so a hijacked
  session can't brute-force the account password for a sudo window.
- **Registration input can no longer self-assign gating columns**
  (`account_status`, `status_expires_at`, `phone_verified_at`,
  `two_factor_required`, …) — the privileged-field denylist was extended.
- **Boot fails fast when `APP_KEY` is unset** — it is the pepper for OTP /
  backup-code hashing and the key for 2FA-secret encryption.

### Security — added (opt-in, default off)

- **`two_factor.required` is now enforced** on the package's own authenticated
  routes via the new `auth.require-2fa-enrolled` middleware, returning a
  `must_enroll_2fa` envelope while leaving the enroll / login / logout / `me` /
  `password/confirm` endpoints reachable. Login also surfaces a
  `must_enroll_2fa` hint.
- **Strict API-token abilities** (`api_tokens.strict_abilities`): a normal user
  may only self-grant abilities from `api_tokens.grantable_abilities` and never
  the `*` wildcard (reserved for admin-issued tokens). New `api_tokens.mode`
  (`customer_auth` | `third_party`) and `api_tokens.max_ttl_days` cap.
- **Step-up for API-token creation** (`api_tokens.require_step_up`).
- **Admin role hierarchy** (`account.status.admin_actions.enforce_role_hierarchy`):
  an admin may only change a strictly lower-ranked account — not a peer, a
  higher role, or themselves — and `deleted` can no longer be set via the
  status endpoint. Configurable `role_ranks`, `allow_self_action`,
  `allow_equal_rank`.
- **OAuth state enforcement for stateless clients** (`social.enforce_state`):
  a one-time server-managed `state` is verified on the mobile/SPA callback,
  closing login-CSRF / authorization-code injection on the stateless path.
- **Account-lockout scope** (`security.lockout.scope`: `email` | `ip` |
  `email_and_ip`) plus optional `backoff`, mitigating the known-email targeted
  lockout DoS.
- **Configurable registration-device trust level**
  (`trusted_devices.registration_device_level`).

### Changed

- Test suite can run against a strict MySQL via `DB_CONNECTION=mysql`; a new
  GitHub Actions workflow runs the full suite on SQLite (PHP 8.2 / 8.3) and on
  strict MySQL.

---

## [2.6.1] — 2026-05-27

Security hardening pass over the v2.6.0 surface. Closes several 2FA-bypass
paths and tightens defaults. **Mostly backward-compatible**; see the two
behavior-change notes below.

### Security — fixed

- **Social login no longer bypasses 2FA.** A user with a verified 2FA method
  who signs in via Google now gets a `challenge_token` (and must complete
  `/auth/2fa/challenge`) exactly like password login, instead of being issued
  a token directly.
- **Social login now runs the full account-status gate.** Social sign-in
  enforces the same `assertCanLogin()` checks as password login (suspended /
  disabled are rejected; self-deactivated accounts auto-reactivate), not just
  the `is_active` flag.
- **Password-reset auto-login no longer bypasses 2FA.** After a reset, a 2FA
  user receives a `challenge_token`; the real token is issued only after the
  second factor is verified.
- **Email OTP / magic-link tokens are now stored as keyed HMAC-SHA256**
  (app-key pepper) instead of plain SHA-256, so a leaked database can't be
  used to reverse low-entropy numeric codes offline. Aligns with the v2.6.0
  phone-OTP hashing.
- **Auth tokens are no longer placed in redirect query strings.** The
  email-verify, password-reset, and social-link frontend redirects now carry
  `completion_token` / `reset_token` / access+refresh tokens in the URL
  **fragment** (`#…`), which is never sent to servers, logs, or `Referer`.
- **`auth.active` is now applied to the default authenticated + admin route
  groups**, so a mid-session suspension/disable takes effect on the very next
  request instead of at token expiry.
- **Step-up protection** (new `auth.step-up` middleware) on destructive 2FA
  actions (remove a method, regenerate backup codes) and phone change. Mode is
  config-driven via `two_factor.step_up_mode` (`password_confirm` default |
  `two_factor`). Admin status-change step-up is available too but **opt-in**
  via `account.status.require_step_up` (default off, to avoid breaking
  existing admin clients).
- **Responses defensively strip `password` / `remember_token`** from the
  serialized user even if the host model omits them from `$hidden`.

### Changed (behavior)

- **Default `password.min_length` raised 8 → 15** (NIST SP 800-63B-4
  single-factor posture; composition rules remain off). Only affects *new*
  passwords; existing hashes are untouched. Override with `AUTH_PASSWORD_MIN`
  (hard floor 8, enforced at boot).
- **`UserLoggedIn` semantics unchanged from 2.6.0** — still fires once at
  credential success.

### Added

- `two_factor.step_up_mode` config + `auth.step-up` middleware.
- `account.status.require_step_up` config (opt-in admin step-up).
- Boot-time validation: `password.min_length` must be ≥ 8.

### Notes / unchanged by design

- The Sanctum **session** access token keeps `['*']` abilities (a user's own
  session acts as the user; ability scoping is for the separate API-token
  feature). Documented, not changed.
- Auth tables intentionally omit DB **foreign keys** to `users` for
  portability across host PK types (UUID, custom table names, engines).

### Upgrade

```bash
composer update joe-404/laravel-auth   # no new migrations in 2.6.1
```

If you relied on the old 8-char password default, set `AUTH_PASSWORD_MIN=8`.
SPA clients must read post-redirect tokens from `window.location.hash`
(fragment) instead of the query string.

---

## [2.6.0] — 2026-05-23

Adds phone capture + verification, full Two-Factor Authentication (TOTP /
Email / SMS), backup codes, and a trusted-device system with time-based
trust levels. New `Require2FA` middleware provides GitHub-style step-up
authentication for sensitive endpoints. **Additive only** — existing users
without 2FA enrolled see no flow changes.

### Added

- **Phone number support** at registration (config-driven required/optional).
  Adds `phone`, `phone_verified_at`, `two_factor_required` columns to the
  users table.
- **Phone verification driver system** with five built-in drivers (log,
  infobip, messagecentral, twilio, firebase) and a `PhoneDriverContract` for
  custom drivers. Per-channel (sms/voice/whatsapp) provider selection with
  optional fallback driver. Default `log` driver writes codes to the Laravel
  log for safe local development.
- **Two-Factor Authentication** with three equal methods:
  - **TOTP**: RFC 6238 authenticator apps via `pragmarx/google2fa`. QR codes
    generated server-side via `bacon/bacon-qr-code`.
  - **Email**: OTP via the existing email channel.
  - **SMS**: OTP via the phone driver system. Requires verified phone first.
    Users can enroll in multiple methods and pick any one at challenge time.
- **Backup codes** (8 codes × 10 chars, configurable) generated on first
  successful 2FA enrollment. Single-use, hashed at rest, regeneratable.
- **Challenge flow at login**: when the user has at least one verified 2FA
  method and the current device is not trusted at the configured threshold,
  login returns `{ requires_2fa: true, challenge_token, available_methods }`
  instead of a Sanctum token. The real token is only issued after
  `POST /auth/2fa/challenge` succeeds. Mirrors GitHub/Stripe/Auth0.
- **Trusted devices** with auto-progression:
  - Registration device auto-trusted at level `high`.
  - User-opt-in trust on login via `trust_device: true` flag at challenge.
  - Time-based progression `low (15d) → medium (60d) → high (90d)`.
  - Three assignment modes (`time`, `time_consistent`, `time_admin`).
  - Revocation matrix: medium can revoke low, high can revoke low+medium,
    any trusted device can revoke all.
- **`Require2FA` middleware (`auth.2fa`)** for sensitive endpoints. Three
  fallback behaviors when the user has no 2FA enrolled: `block`,
  `force_enroll`, `password_confirm` (sudo mode, default).
- **Password sudo mode**: `POST /auth/password/confirm` issues a 15-minute
  step-up window that the `Require2FA` middleware accepts in lieu of a 2FA
  challenge.
- **`Request::authContext()`** macro returning a read-only snapshot:
  `{ 2fa_enabled, 2fa_verified, trust_level, phone_verified, sudo_active }`.
- **New endpoints**:
  - `POST /auth/phone/send-otp`, `POST /auth/phone/verify`
  - `GET /auth/2fa/methods`, `POST /auth/2fa/enroll/{method}/{start|verify}`,
    `DELETE /auth/2fa/methods/{id}`, `POST /auth/2fa/methods/{id}/default`
  - `GET /auth/2fa/backup-codes`, `POST /auth/2fa/backup-codes/regenerate`
  - `POST /auth/2fa/challenge`, `POST /auth/2fa/challenge/switch`,
    `POST /auth/2fa/challenge/resend`
  - `POST /auth/password/confirm`
  - `GET /auth/trusted-devices`, `DELETE /auth/trusted-devices`,
    `DELETE /auth/trusted-devices/{id}`
- **`auth:install --upgrade`** flag for migrating existing installations from
  v2.5.x. Runs only the new v2.6 migrations and prints a changelog summary.
- **Social profile completion** — `social.profile_completion.enabled` makes a
  brand-new OAuth (Google) user complete the host's required registration
  fields via `POST /auth/social/complete` before the account is created,
  enforcing the same `registration.extra_fields_rules` + phone rules as the
  email flow. No user row is created until completion. New config keys
  `AUTH_SOCIAL_PROFILE_COMPLETION` / `AUTH_SOCIAL_PROFILE_COMPLETION_TTL`.

### Changed (compared to v2.5)

- `UserLoggedIn` still fires at credential success — even when 2FA is required
  and no token is issued yet — so existing listeners (audit, "user logged in"
  notifications) continue to fire on the credential leg. The new
  `TwoFactorChallengeIssued` event fires at the same boundary when a challenge
  is created, and `TwoFactorVerified` fires once the user completes the
  challenge.

### Security

- **Trusted-device 2FA bypass now requires a server-issued token, not just a
  fingerprint match.** Each trusted device gets a one-time random
  `trusted_device_token` at trust time (returned in the registration response
  and in `/auth/2fa/challenge` responses when `trust_device=true`). The
  client must echo it back as `X-Trusted-Device-Token` on subsequent logins
  for the bypass to apply. Fingerprint alone — which is client-supplied —
  no longer grants MFA bypass.
- **Default `bypass_2fa_min_level` raised from `medium` to `high`** so the
  bypass requires the strongest trust signal (registration device, 90 days
  of usage, or admin grant). Hosts that intentionally want the looser
  v2.6.0-rc semantics can override with `AUTH_TRUST_BYPASS_MIN=medium`.

### New dependencies

- `pragmarx/google2fa: ^8.0`
- `bacon/bacon-qr-code: ^3.0`

### Migration notes

All v2.6 migrations are stamped `2025_v260_*`. Existing installations:

```bash
composer update joe-404/laravel-auth
php artisan auth:install --upgrade
```

Then add the three new columns to your User model's `$fillable`:

```php
protected $fillable = [/* …existing… */, 'phone', 'phone_verified_at', 'two_factor_required'];
```

See the "Upgrading to v2.6.0 from v2.5.x" section in `docs/upgrading.md` for the full guide.

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

| File                                                               | Creates / Alters                                      |
| ------------------------------------------------------------------ | ----------------------------------------------------- |
| `2026_05_20_000001_create_referrals_table`                         | `referrals` table                                     |
| `2026_05_20_000002_add_fingerprint_hash_to_auth_sessions_extended` | `fingerprint_hash` column on `auth_sessions_extended` |
| `2026_05_20_000003_create_auth_user_devices_table`                 | `auth_user_devices` table                             |

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
