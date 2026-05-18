# AI Context — joe-404/laravel-auth

> **For developers:** this file exists so you can paste it (or the full repo snapshot from [gitingest](https://gitingest.com/joe-nassar-tech/laravel-auth)) straight into any AI assistant and get accurate, repo-aware help immediately — without explaining the project from scratch every time.
>
> **How to get a full snapshot of the repo for your AI:**
> 1. Open [https://gitingest.com/joe-nassar-tech/laravel-auth](https://gitingest.com/joe-nassar-tech/laravel-auth)
> 2. Set **Include files under** to `100kB`
> 3. Click **Ingest**
> 4. Under the summary, click **Copy All** and paste into your AI — or **Download** as an `.md` file and attach it
>
> That gives the AI the actual source code of every file. Use this `AI_Context.md` when you want a quick conceptual briefing; use gitingest when you need the AI to read or edit code.

---

## What this repository is

**Package name:** `joe-404/laravel-auth`
**Packagist:** `composer require joe-404/laravel-auth`
**Type:** Composer library — NOT a Laravel app, NOT a standalone service
**Purpose:** Drop-in, config-driven authentication library for Laravel 12/13. Installs via `composer require` + `php artisan auth:install`, then exposes a complete JSON auth API with zero frontend coupling.

The package covers everything a backend auth system needs:
- 3-step email-first registration (initiate → verify → complete)
- OTP and/or magic-link email verification
- Session (cookie) and Bearer token login with automatic client detection
- Refresh token rotation (dedicated table, one-time-use, atomic)
- Password reset (OTP or magic link)
- Authenticated password change
- Session management (list + revoke individual or all)
- Google OAuth with safe account-linking
- Long-lived scoped API tokens for third-party integrations (opt-in)
- Per-IP + per-email rate limiting
- Account lockout after repeated failed logins
- Account status system (active / suspended / disabled / deactivated / deleted) with admin endpoints and middleware enforcement
- Timed bans (auto-unban via lazy revert + scheduled sweep)
- Account deactivation (Instagram-style self-pause, auto-reactivates on login)
- Account deletion with 30-day grace period and auto-restore on login
- Multi-admin audit log for all status changes and admin notes
- Multi-language support (translation files per locale)
- New device login email alerts
- Laravel Reverb WebSocket push (optional)
- Fully customizable: response format, email templates, OTP delivery channel, referral codes, extra registration fields, field transformers

---

## Tech stack

| Layer | Technology |
|---|---|
| Language | PHP `^8.2` |
| Framework | Laravel `^12.0` \| `^13.0` |
| Auth tokens | Laravel Sanctum `^4.0` |
| Roles/permissions | Spatie Laravel Permission `^6.0` |
| Social auth | Laravel Socialite `^5.0` |
| Device detection | jenssegers/agent |
| Cache/queue | Redis (recommended; any Laravel-supported driver works) |
| Testing | Pest with `RefreshDatabase`, `Mail::fake()`, `Queue::fake()` |
| Runtime safety | Octane/Swoole compatible — no request state on singletons |

---

## Repository layout

```
joe-404/laravel-auth/
├── config/
│   └── auth_system.php            ← Single config file; every option has an env variable
│
├── database/
│   ├── migrations/                ← 6 migration files the package owns
│   │   ├── 2024_01_01_000001_add_columns_to_users_table.php    (last_login_at, is_active)
│   │   ├── 2024_01_01_000002_create_auth_otp_codes_table.php
│   │   ├── 2024_01_01_000003_create_auth_sessions_extended_table.php
│   │   ├── 2024_01_01_000004_create_auth_social_accounts_table.php
│   │   ├── 2024_01_01_000005_create_auth_api_tokens_table.php
│   │   ├── 2024_01_01_000006_create_auth_refresh_tokens_table.php
│   │   ├── 2026_05_16_000001_add_account_status_to_users_table.php (v2.4)
│   │   ├── 2026_05_16_000002_create_deleted_accounts_table.php     (v2.4)
│   │   ├── 2026_05_17_000003_add_status_expires_at_to_users_table.php (v2.4)
│   │   └── 2026_05_17_000005_create_account_status_logs_table.php  (v2.4)
│   └── seeders/
│       └── AuthRolesSeeder.php    ← Creates default roles via Spatie Permission
│
├── docs/
│   ├── AI_Context.md              ← THIS FILE
│   ├── configuration.md           ← Complete config reference (every key documented)
│   ├── customization.md           ← Extra fields, transformers, referral codes
│   ├── events.md                  ← Event system, listeners, auto-discovery
│   ├── installation.md            ← Step-by-step install, troubleshooting
│   ├── localization.md            ← Multi-language, translation files
│   ├── upgrading.md               ← Version migration guides
│   ├── account-status.md          ← Account status system, timed bans, admin endpoints
│   └── account-deletion.md        ← Soft-delete, grace period, purge worker
│
├── resources/
│   ├── devices.json               ← ~500 device model definitions for UA parsing
│   ├── lang/
│   │   ├── en/messages.php        ← English success messages
│   │   └── en/errors.php          ← English error messages
│   └── views/emails/              ← Blade email templates (publishable)
│
├── routes/
│   └── auth.php                   ← All package routes (33 routes total)
│
├── src/
│   ├── AuthServiceProvider.php    ← Registers everything; mounts routes; schedules jobs
│   │
│   ├── Commands/
│   │   └── InstallCommand.php     ← `php artisan auth:install`
│   │
│   ├── Concerns/
│   │   └── HasAccountStatus.php   ← Trait mixed into the host User model
│   │
│   ├── Contracts/                 ← 6 interfaces — the package's extension API
│   │   ├── ResponseFormatterContract.php
│   │   ├── OtpChannelContract.php
│   │   ├── CombinedOtpChannelContract.php
│   │   ├── ExtraFieldTransformerContract.php
│   │   ├── ReferralCodeGeneratorContract.php
│   │   └── DeviceResolverContract.php
│   │
│   ├── Events/                    ← Dispatched at key lifecycle moments
│   │   ├── EmailVerified.php
│   │   ├── UserLoggedIn.php
│   │   ├── UserLoggedOut.php
│   │   ├── PasswordChanged.php
│   │   ├── UserRegistered.php
│   │   ├── SuspiciousLoginDetected.php
│   │   ├── AccountStatusChanged.php
│   │   ├── AccountDeleted.php
│   │   ├── AccountRestored.php
│   │   └── AccountPurged.php
│   │
│   ├── Exceptions/                ← Typed exceptions (never return false to signal failure)
│   │   ├── AuthException.php      ← Base exception
│   │   ├── AccountInactiveException.php
│   │   ├── EmailNotVerifiedException.php
│   │   ├── OtpInvalidException.php
│   │   ├── OtpExpiredException.php
│   │   ├── TokenExpiredException.php
│   │   └── TokenRevokedException.php
│   │
│   ├── Http/
│   │   ├── Concerns/
│   │   │   ├── RespondsWithJson.php    ← Trait used by ALL controllers; resolves formatter
│   │   │   └── ResolvesMessages.php
│   │   ├── Controllers/
│   │   │   ├── RegisterController.php
│   │   │   ├── LoginController.php
│   │   │   ├── LogoutController.php
│   │   │   ├── EmailVerificationController.php
│   │   │   ├── PasswordResetController.php
│   │   │   ├── PasswordChangeController.php
│   │   │   ├── TokenRefreshController.php
│   │   │   ├── SessionController.php
│   │   │   ├── SocialAuthController.php
│   │   │   ├── ApiTokenController.php
│   │   │   ├── AccountController.php           ← deactivate, delete
│   │   │   └── Admin/
│   │   │       ├── UserStatusController.php    ← GET|POST /admin/users/{id}/status
│   │   │       └── UserAuditController.php     ← GET history, POST notes
│   │   ├── Formatters/
│   │   │   └── DefaultResponseFormatter.php
│   │   ├── Middleware/
│   │   │   ├── AuthMode.php               ← switches session vs token based on config
│   │   │   ├── ApiTokenAuth.php           ← authenticates auth_at_* tokens
│   │   │   ├── RequireEmailVerified.php
│   │   │   ├── RequireActiveAccount.php   ← `auth.active` — blocks suspended/disabled mid-session
│   │   │   ├── RateLimitAuth.php
│   │   │   ├── DeviceFingerprint.php
│   │   │   ├── ConditionalCsrf.php
│   │   │   ├── FeatureFlag.php
│   │   │   └── RejectRefreshToken.php
│   │   └── Requests/                      ← One FormRequest per endpoint
│   │
│   ├── Jobs/
│   │   ├── CleanExpiredOtpRecords.php       ← every 5 min
│   │   ├── CleanExpiredRefreshTokens.php    ← hourly
│   │   ├── CleanExpiredApiTokens.php        ← hourly (only when api_tokens.enabled)
│   │   ├── RevertExpiredAccountStatuses.php ← every N min (timed ban sweep)
│   │   └── PurgeExpiredAccountDeletions.php ← hourly (delete grace period)
│   │
│   ├── Listeners/
│   │   ├── SendVerificationNotification.php
│   │   └── NotifySuspiciousLogin.php
│   │
│   ├── Models/
│   │   ├── AuthOtpCode.php
│   │   ├── AuthSessionExtended.php
│   │   ├── AuthRefreshToken.php
│   │   ├── AuthSocialAccount.php
│   │   ├── AuthApiToken.php
│   │   ├── AccountStatusLog.php
│   │   └── DeletedAccount.php
│   │
│   ├── Notifications/             ← One class per email; all publishable via config
│   │
│   ├── Services/                  ← All business logic lives here
│   │   ├── AuthService.php        ← registration + login (the core service)
│   │   ├── OtpService.php         ← OTP generation, verification, magic links
│   │   ├── TokenService.php       ← Sanctum token issuance + refresh rotation
│   │   ├── ApiTokenService.php    ← Long-lived API token CRUD
│   │   ├── SessionService.php     ← AuthSessionExtended list + revoke
│   │   ├── DeviceService.php      ← UA parsing + new-device detection
│   │   ├── RateLimitService.php   ← Per-IP + per-email rate limit checks
│   │   ├── LockoutService.php     ← Per-account lockout after failed logins
│   │   ├── SocialAuthService.php  ← Google OAuth + account linking
│   │   ├── AccountStatusService.php  ← Status read/write, lazy auto-unban
│   │   ├── AccountAuditService.php   ← Audit log writes (logStatusChange, logNote)
│   │   ├── AccountDeletionService.php ← Soft-delete, restore, purge
│   │   ├── DefaultReferralCodeGenerator.php
│   │   └── UniqueColumnResolver.php  ← Introspects schema for unique columns to null on purge
│   │
│   └── Support/
│       └── AccountStatus.php      ← Enum-like constants: ACTIVE, SUSPENDED, DISABLED, DELETED, DEACTIVATED
│
└── tests/
    ├── Feature/Auth/              ← End-to-end flow tests
    ├── Feature/Sessions/
    ├── Feature/ApiTokens/
    ├── Feature/RateLimiting/
    ├── Feature/Account/           ← TimedBanTest, DeactivateTest, AuditLogTest
    └── Unit/Services/
```

---

## Database tables

| Table | Owner | Purpose |
|---|---|---|
| `users` | Host app (altered by package) | The main users table. Package adds: `last_login_at`, `is_active`, `account_status`, `status_changed_at`, `status_reason`, `status_expires_at` |
| `auth_otp_codes` | Package | SHA-256 hashed OTP codes and magic-link UUIDs. Shared between registration and password reset |
| `auth_sessions_extended` | Package | One row per active session — device, browser, OS, IP, geo. Used by session list/revoke |
| `auth_refresh_tokens` | Package | One-time-use refresh tokens with family tracking for reuse detection |
| `auth_social_accounts` | Package | Links Google accounts to local users |
| `auth_api_tokens` | Package | Long-lived scoped API tokens (`auth_at_*` format) |
| `personal_access_tokens` | Sanctum | Standard Sanctum access tokens |
| `account_status_logs` | Package (v2.4) | Audit log — every status change and admin note with actor/source/comment context |
| `deleted_accounts` | Package (v2.4) | Permanent snapshot of the users row after soft-delete, kept even after hard-delete for FK integrity |
| `roles`, `permissions`, etc. | Spatie Permission | Roles and permissions |

---

## Key flows (step by step)

### Registration (3-step)

```
POST /auth/register
  ↓ validate email + extra_fields_rules
  ↓ cache pending registration (auth_system.password.pending_ttl_minutes)
  ↓ OtpService::send() → OTP + magic link (method = otp | magic_link | both)
  ↓ return temp_token (UUID, used for Reverb subscription)
  ↓
POST /auth/register/verify-otp   OR   GET /auth/register/verify-magic/{token}
  ↓ verify OTP hash OR signed URL
  ↓ return completion_token (UUID, 15 min TTL, stored in Redis)
  ↓
POST /auth/register/complete
  ↓ validate completion_token, password
  ↓ DB::transaction():
  │   User::create() with email + extra fields + transformed fields
  │   assign default_role via Spatie
  │   generate referral_code (if enabled)
  ↓ dispatch EmailVerified event (host listeners run here)
  ↓ TokenService::issue() → Sanctum token + refresh token
  ↓ return user + token + refresh_token (or session cookie in web mode)
```

### Login

```
POST /auth/login
  ↓ validate email + password
  ↓ RateLimitService::check() (per IP + per email)
  ↓ LockoutService::check() (per account)
  ↓ Hash::check() against users.password
  ↓ AccountStatusService::assertCanLogin() — blocks suspended/disabled
  ↓   if status = deactivated AND auto_reactivate_on_login:
  │       AccountStatusService::changeStatus(ACTIVE, source=login_auto_reactivate)
  ↓   if status = deleted AND within grace AND auto_restore_on_login:
  │       AccountDeletionService::restore()
  ↓ DeviceService::resolve() — check for new device → SuspiciousLoginDetected event
  ↓ AuthSessionExtended::create() — record session row
  ↓ dispatch UserLoggedIn event
  ↓ TokenService::issue() → access + refresh tokens (or session)
  ↓ return user + token + refresh_token
```

### `auth.active` middleware (per-request enforcement)

```
Every request to a protected route (fan/* or admin/*):
  ↓ RequireActiveAccount middleware
  ↓ AccountStatusService::current($user)
  ↓   revertIfExpired() — lazy auto-unban check
  │     if suspended AND status_expires_at < now():
  │         changeStatus(ACTIVE, source=auto_unban_lazy)
  ↓   if status in login_blocked → 403 with per-status error key
  ↓ Continue to controller
```

### Timed ban auto-unban (sweep path)

```
RevertExpiredAccountStatuses job (every N minutes, scheduled by AuthServiceProvider):
  ↓ WHERE account_status IN temporary_statuses AND status_expires_at < now()
  ↓ For each: AccountStatusService::revertIfExpired($user, 'auto_unban_sweep')
  ↓ changeStatus(ACTIVE) → revoke_sessions=false (user is already out)
  ↓ AccountAuditService::logStatusChange(source=auto_unban_sweep)
  ↓ dispatch AccountStatusChanged event
```

### Refresh token rotation

```
POST /auth/token/refresh
  ↓ find AuthRefreshToken by hash — 401 if not found
  ↓ DB::transaction() + SELECT FOR UPDATE (atomic)
  ↓ if already consumed → reuse detected → revoke entire family → 401
  ↓ mark old refresh token consumed
  ↓ revoke paired Sanctum access token
  ↓ issue new access token + new refresh token (same family)
  ↓ return new token pair
```

---

## Configuration system

Everything lives in `config/auth_system.php`. The file is published to the host app via `php artisan vendor:publish --tag=auth-config`. Every key that reads `env()` can be overridden via `.env`. Keys that don't read `env()` must be edited directly in the published config file.

**Full documentation:** `docs/configuration.md` — covers every single key with explanation, valid values, and examples.

**Quick overview of top-level sections:**

| Section | What it controls |
|---|---|
| `mode` | `api` / `web` / `both` — how credentials are issued |
| `spa_token` | Whether browser clients get a token instead of a cookie in `both` mode |
| `require_email_verification` | Block login until email is verified |
| `routes` | `register` (auto-mount on/off), `prefix` (URL prefix), `middleware` (override stack) |
| `registration` | `extra_fields_rules`, `extra_fields_messages`, `extra_fields_transformers`, `request_class` |
| `referral_code` | Auto-generate unique referral codes per user |
| `verification` | OTP length/expiry, magic link target, frontend URLs |
| `password_reset` | Override verification method for password resets |
| `token_ttl` | Access + refresh token lifetimes per client type (mobile / spa / api) |
| `rate_limits` | Per-endpoint rate limit strings (`"max:decay_minutes"`) |
| `password` | Min length, uppercase/number/special requirements, pending TTL |
| `roles` | Default role for new users, seeded roles |
| `otp_channel` | `email` or FQCN of a custom `OtpChannelContract` implementation |
| `mail` | Override individual notification classes; toggle lifecycle notifications on/off |
| `social` | Google OAuth credentials + frontend redirect URL |
| `reverb` | Enable WebSocket real-time verification push |
| `api_tokens` | Enable long-lived API token system |
| `queue` | Queue connection + name for maintenance jobs |
| `response` | Custom response formatter class |
| `security` | New-device alerts, lockout settings |
| `account.status` | Status column, allowed statuses, login-blocked list, auto-unban |
| `account.deletion` | Self-service delete, grace period, purge behaviour |
| `account.deactivation` | Self-service pause, auto-reactivate on login |
| `account.audit` | Audit log table, retention, notes endpoint, history endpoint |
| `errors` | Override any error message string by key |
| `messages` | Override any success message string by key |

---

## Extension points (contracts)

The package exposes 6 PHP interfaces. Implement any of them to replace a piece of built-in behaviour without forking the package.

| Interface | Located at | Registered via | Replaces |
|---|---|---|---|
| `ResponseFormatterContract` | `src/Contracts/` | `response.formatter` config or `app()->bind()` | JSON envelope structure |
| `OtpChannelContract` | `src/Contracts/` | `otp_channel.driver` config | OTP/magic-link delivery (email by default) |
| `CombinedOtpChannelContract` | `src/Contracts/` | (extends OtpChannelContract) | Single-message combined OTP + link |
| `ExtraFieldTransformerContract` | `src/Contracts/` | `registration.extra_fields_transformers` config | Derive/normalize a field from validated registration input |
| `ReferralCodeGeneratorContract` | `src/Contracts/` | `referral_code.generator` config | Referral code generation algorithm |
| `DeviceResolverContract` | `src/Contracts/` | `app()->bind()` in AppServiceProvider | User-Agent / device fingerprint parsing |

---

## Events

The package dispatches events and never calls host-app code directly. Host apps listen to whatever events they care about using standard Laravel auto-discovered listeners in `app/Listeners/`.

| Event class | Namespace | Fired when | Payload |
|---|---|---|---|
| `EmailVerified` | `Joe404\LaravelAuth\Events\` | Registration step 3 succeeds | `$user`, `$tempToken` |
| `UserLoggedIn` | `Joe404\LaravelAuth\Events\` | Successful login | `$user`, `$request` |
| `UserLoggedOut` | `Joe404\LaravelAuth\Events\` | Any logout | — |
| `PasswordChanged` | `Joe404\LaravelAuth\Events\` | Password reset or change | `$user` |
| `SuspiciousLoginDetected` | `Joe404\LaravelAuth\Events\` | Login from new device | `$user`, `$ip`, `$browser`, `$os`, `$city`, `$country` |
| `AccountStatusChanged` | `Joe404\LaravelAuth\Events\` | Any status change | `$user`, `$from`, `$to`, `$source` |
| `AccountDeleted` | `Joe404\LaravelAuth\Events\` | Soft-delete initiated | `$user` |
| `AccountRestored` | `Joe404\LaravelAuth\Events\` | Auto-restore on login | `$user` |
| `AccountPurged` | `Joe404\LaravelAuth\Events\` | Purge worker ran | `$deletedAccount` |

**Auto-discovery:** drop a class in `app/Listeners/` with a `handle(EventClass $event)` method — Laravel 11+ wires it automatically. No `Event::listen()` needed and no service provider edits.

---

## Account status system (v2.4)

### Statuses

| Value | Set by | Login behaviour | Notes |
|---|---|---|---|
| `active` | System (default) | Allowed | Normal state |
| `suspended` | Admin only | Blocked (401) | Can be timed — carries `status_expires_at` |
| `disabled` | Admin only | Blocked (401) | Permanent violation ban (Meta-style). No expiry allowed |
| `deactivated` | User self-service | Auto-reactivates on login | Instagram-style pause |
| `deleted` | User or Admin | Auto-restores on login within grace | 30-day soft-delete grace |

### Admin endpoints (requires admin role)

```
GET  /auth/admin/users/{id}/status              → read current status
POST /auth/admin/users/{id}/status              → change status (body: status, reason, comment, expires_at|duration_minutes)
GET  /auth/admin/users/{id}/status/history      → paginated audit log (filters: actor_type, action, from, to)
POST /auth/admin/users/{id}/notes               → add standalone note without changing status
```

### Self-service endpoints (requires auth)

```
POST   /auth/account/deactivate    → set own status to deactivated (password required by default)
DELETE /auth/account               → soft-delete own account with 30-day grace
```

### `auth.active` middleware

Alias: `auth.active` — registered by the package's `AuthServiceProvider`.

Apply to any route group to block `suspended` and `disabled` accounts mid-session:

```php
Route::middleware(['auth:sanctum', 'auth.active', 'role:fan'])->group(function () {
    // fan routes
});
```

---

## Middleware registered by the package

All middleware is registered in `AuthServiceProvider::boot()`:

| Alias | Class | Purpose |
|---|---|---|
| `auth.mode` | `AuthMode` | Switches token vs session based on `AUTH_MODE` |
| `auth.active` | `RequireActiveAccount` | Blocks suspended/disabled accounts per-request |
| `auth.verified` | `RequireEmailVerified` | Blocks unverified email |
| `auth.rate` | `RateLimitAuth` | Applies per-endpoint rate limits |
| `auth.device` | `DeviceFingerprint` | Populates device session metadata |
| `auth.api_token` | `ApiTokenAuth` | Authenticates `auth_at_*` API tokens |
| `auth.feature` | `FeatureFlag` | Guards optional features (api_tokens, deactivation, deletion) |

---

## Response envelope

Every controller uses the `RespondsWithJson` trait which resolves the configured formatter. Default output:

```json
// Success
{ "success": true,  "message": "Login successful.",  "data": { "user": {...}, "token": "..." } }

// Error
{ "success": false, "message": "Invalid credentials.", "errors": {} }

// Validation error (422)
{ "success": false, "message": "The given data was invalid.", "errors": { "email": ["..."] } }
```

Override the format by implementing `ResponseFormatterContract` — see `docs/configuration.md`.

---

## Coding conventions (must follow when editing or extending)

These are non-negotiable — enforced by code review and static analysis:

1. `declare(strict_types=1)` at the top of **every** PHP file
2. Return type on **every** method — `mixed` only when truly unavoidable
3. **Typed exceptions only** — never `return false` to signal failure
4. **Never store `$this->request` on a service** — inject the request at the method level (Octane/Swoole singleton safety)
5. Rate limit cache keys follow the pattern: `auth:{configKey}:{subject}` (e.g. `auth:login:127.0.0.1`)
6. Tests: Pest + `RefreshDatabase` + `Mail::fake()` + `Queue::fake()` — never real email or HTTP
7. All new features need a corresponding test file under `tests/Feature/` or `tests/Unit/`

---

## Versioning history

| Version | What was added |
|---|---|
| v1.0.0 | Core auth: register → verify → login → logout, OTP + magic link, session mode |
| v2.0.0 | Security hardening: refresh token table, 3-step registration (password at step 3), rate limiting, lockout |
| v2.1.0 | Session management, device tracking, new-device alerts, Google OAuth |
| v2.2.0 | Referral codes, custom response messages, extra-field validation messages, extra-field transformers, response formatter contract |
| v2.3.0 | Multi-language support (translation files, `trans()` pipeline, Arabic bundled) |
| v2.4.0 | Account status system (active/suspended/disabled/deactivated/deleted), timed bans + auto-unban, self-service deactivation and deletion, multi-admin audit log, admin status endpoints, `auth.active` middleware, `RevertExpiredAccountStatuses` + `PurgeExpiredAccountDeletions` jobs |
| v2.4.1 | `routes.prefix` config option (host can mount at any URL prefix e.g. `api/v1/auth`) |
| v2.4.2 | MySQL strict mode fix: nullable timestamps in `deleted_accounts` migration |

---

## Common integration patterns

### Mounting at a versioned URL prefix

```php
// config/auth_system.php
'routes' => [
    'prefix' => 'api/v1/auth',   // → /api/v1/auth/login, /api/v1/auth/register, etc.
],
```

### Adding fan-specific registration fields

```php
// config/auth_system.php
'registration' => [
    'extra_fields_rules' => [
        'username'       => ['required', 'string', 'min:3', 'max:30', 'unique:users,username'],
        'date_of_birth'  => ['required', 'date', new \App\Rules\Age18Plus()],
        'agreed_terms'   => ['required', 'accepted'],
        'agreed_18_plus' => ['required', 'accepted'],
    ],
    'extra_fields_messages' => [
        'agreed_terms.accepted' => 'You must accept the terms to continue.',
    ],
],
```

### Reacting to registration completion (no controller changes)

```php
// app/Listeners/SeedFanWallet.php
use Joe404\LaravelAuth\Events\EmailVerified;

class SeedFanWallet
{
    public function handle(EmailVerified $event): void
    {
        Wallet::create(['user_id' => $event->user->id, 'balance' => 0]);
    }
}
```

### Applying `auth.active` middleware

```php
// routes/api/fan.php
Route::middleware(['auth:sanctum', 'auth.active', 'role:fan'])->group(function () {
    Route::get('dashboard', DashboardController::class);
});
```

### Overriding a single error message

```php
// config/auth_system.php
'errors' => [
    'account_suspended' => 'Your account is temporarily on hold. Contact support for help.',
],
```

---

## What the package does NOT do

- No frontend, no Blade views for auth pages (emails only)
- No appeal workflow for disabled accounts (planned for a future release)
- No built-in RBAC beyond Spatie Permission role assignment
- No SMS/WhatsApp delivery out of the box — implement `OtpChannelContract` for that
- No rate limit storage — uses whatever cache driver Laravel is configured with
- Does not install Reverb — that is a separate package; `AUTH_REVERB_ENABLED` only adds the broadcast call

---

## Suggested prompt prefix for AI sessions

When asking an AI for help with this repo, start with:

> "I'm working on the `joe-404/laravel-auth` Composer package (PHP 8.2, Laravel 13, Sanctum, Spatie Permission). It provides a drop-in JSON auth API via `composer require joe-404/laravel-auth`. The package follows strict-types, typed exceptions, Octane-safe singletons, and uses Pest for testing. [Paste gitingest snapshot here or describe your specific question.]"

Or paste this entire file to give the AI the full conceptual map, then attach the gitingest snapshot for access to the actual source code.
