# Middleware Reference

Every HTTP middleware the package registers, what it does, where to apply it, what it returns on failure, and how to order it. All package middleware is registered automatically by `AuthServiceProvider` — you do **not** need to add anything to `bootstrap/app.php`. Just reference the alias on your routes.

> All failure responses use the same JSON envelope as the rest of the package — `{ "success": false, "message": "...", "errors": {} }` — resolved through your configured `ResponseFormatterContract`. Examples below show the default formatter's output.

---

## v2.7+ additions (quick reference)

Three new middleware aliases were registered in v2.7.1. The alias cheat-sheet and per-middleware sections below do not yet list them. Quick reference:

| Alias | Class | What it does | Since |
|-------|-------|--------------|-------|
| `auth.require-2fa-enrolled` | `EnforceRequired2FA` | When `two_factor.required=true`, blocks the package's authenticated routes for users who haven't enrolled a 2FA method, returning `data.must_enroll_2fa: true`. Exempts `/me`, `/logout` (+ `/logout/all`), `/password/confirm`, `/session/clear`, and the entire `/2fa/*` enrollment surface so users can still enroll or log out. No-op when `two_factor.required=false` (default). | v2.7.1 |
| `auth.api-token-stepup` | `RequireStepUpForApiTokenCreation` | Gates API-token routes behind a fresh sudo / 2FA step-up when the matching config flag is on. Takes the flag's config path as a parameter so the same class drives multiple gates: `auth.api-token-stepup` (user POST, reads `api_tokens.require_step_up`), `auth.api-token-stepup:auth_system.api_tokens.require_step_up_for_revoke` (user DELETE — v2.7.3), `auth.api-token-stepup:auth_system.api_tokens.admin_require_step_up` (admin POST/PATCH/DELETE — v2.7.1 POST / v2.7.2 PATCH+DELETE). The flag is read at request time, so `route:cache` and per-environment config both behave. | v2.7.1 |
| `auth.admin-gate` | `AdminGate` | Replaces the hard-coded `role:` middleware on the package's admin route groups. Takes a config section as a parameter (`auth.admin-gate:account.status`, `auth.admin-gate:api_tokens`, `auth.admin-gate:referral_code`) and reads `auth_system.<section>.admin_middleware` (override) or `admin_ability` (fallback) at request time. Each pipe-separated token is treated as **a role OR a Spatie permission** — passes on the first match. Lets you gate by role (`super-admin\|admin`), by permission (`users.manage-status`), or any mix. | v2.7.1 (account.status, api_tokens) / v2.7.3 (referral_code) |

The classes live at `src/Http/Middleware/EnforceRequired2FA.php`, `src/Http/Middleware/RequireStepUpForApiTokenCreation.php`, and `src/Http/Middleware/AdminGate.php`. Full per-flag behavior is documented in the root [`UPGRADING.md`](../UPGRADING.md).

---

## Table of Contents

- [Alias cheat-sheet](#alias-cheat-sheet)
- [Built-in Laravel middleware the package depends on](#built-in-laravel-middleware-the-package-depends-on)
- [How the package mounts its own routes](#how-the-package-mounts-its-own-routes)
- [Authentication & identity](#authentication--identity)
  - [`auth.device` — DeviceFingerprint](#authdevice--devicefingerprint)
  - [`auth.api-token` — ApiTokenAuth](#authapi-token--apitokenauth)
  - [`auth.no-refresh` — RejectRefreshToken](#authno-refresh--rejectrefreshtoken)
- [Account gating](#account-gating)
  - [`auth.verified` — RequireEmailVerified](#authverified--requireemailverified)
  - [`auth.active` — RequireActiveAccount](#authactive--requireactiveaccount)
- [Step-up / sensitive actions](#step-up--sensitive-actions)
  - [`auth.2fa` — Require2FA](#auth2fa--require2fa)
  - [`auth.step-up` — RequireStepUp](#authstep-up--requirestepup)
- [Feature & mode gating](#feature--mode-gating)
  - [`auth.feature` — FeatureFlag](#authfeature--featureflag)
  - [`auth.mode` — AuthMode](#authmode--authmode)
  - [`auth.ratelimit` — RateLimitAuth](#authratelimit--ratelimitauth)
- [Internal / route-stack middleware](#internal--route-stack-middleware)
  - [ConditionalCsrf](#conditionalcsrf)
- [Third-party (Spatie) aliases](#third-party-spatie-aliases)
  - [`role`, `permission`, `role_or_permission`](#role-permission-role_or_permission)
- [Recommended ordering](#recommended-ordering)
- [Recipes](#recipes)

---

## Alias cheat-sheet

| Alias                 | Class                  | One-liner                                                               | Auth required first?           |
| --------------------- | ---------------------- | ----------------------------------------------------------------------- | ------------------------------ |
| `auth.device`         | `DeviceFingerprint`    | Parses the device fingerprint onto the request; touches the session row | No                             |
| `auth.api-token`      | `ApiTokenAuth`         | Authenticates a third-party `auth_at_*` token + checks abilities        | No (it _is_ the authenticator) |
| `auth.no-refresh`     | `RejectRefreshToken`   | Blocks refresh tokens from being used as access tokens                  | Yes (`auth:sanctum`)           |
| `auth.verified`       | `RequireEmailVerified` | Rejects users whose email is unverified                                 | Yes                            |
| `auth.active`         | `RequireActiveAccount` | Rejects disabled/suspended accounts mid-session                         | Yes                            |
| `auth.2fa`            | `Require2FA`           | Step-up: forces a fresh 2FA challenge / password confirm                | Yes                            |
| `auth.step-up`        | `RequireStepUp`        | Config-driven step-up (password-confirm or 2FA) for sensitive actions   | Yes                            |
| `auth.feature`        | `FeatureFlag`          | 404s a route group unless `auth_system.<feature>.enabled` is true       | No                             |
| `auth.mode`           | `AuthMode`             | 403s a route unless `auth_system.mode` is in an allow-list              | No                             |
| `auth.ratelimit`      | `RateLimitAuth`        | Per-IP + per-email throttle keyed on a config entry                     | No                             |
| `role` / `permission` | Spatie                 | Role / permission gate (re-aliased by this package)                     | Yes                            |

---

## Built-in Laravel middleware the package depends on

Some package middleware only works **after** a Laravel/Sanctum built-in has already run. These are framework middleware, not shipped by this package — but the package's routes require them, and so do yours if you reuse the package middleware on your own routes. The package already wires them into its own route groups; this section tells you which ones matter and why.

### `auth:sanctum` — **REQUIRED before most package middleware**

**Ships with:** Laravel Sanctum (`laravel/sanctum`, a hard dependency of this package).

**Why it's required.** `auth:sanctum` is what actually authenticates the request — it resolves the bearer token or session cookie into `$request->user()`. Almost every package middleware downstream reads `$request->user()`:

- `auth.no-refresh` inspects `$request->user()?->currentAccessToken()`.
- `auth.verified` calls `$user->hasVerifiedEmail()`.
- `auth.active` reads the user's account status.
- `auth.2fa` needs to know _who_ to challenge.

If you put any of these on a route **without** `auth:sanctum` in front, `$request->user()` is `null`: `auth.verified`/`auth.active` silently pass through (they treat "no user" as "nothing to gate"), and `auth.2fa` returns `401 Unauthenticated`. So: **always list `auth:sanctum` first** on protected routes.

```php
// ✅ correct — sanctum authenticates, then package middleware gate
Route::middleware(['auth:sanctum', 'auth.verified', 'auth.2fa'])->group(/* … */);

// ❌ wrong — package middleware run with no authenticated user
Route::middleware(['auth.verified', 'auth.2fa'])->group(/* … */);
```

> The one exception is `auth.api-token`: it is itself an authenticator (for `auth_at_*` tokens) and calls `Auth::setUser()`. Use it **instead of** `auth:sanctum`, not after it.

**Setup.** Sanctum is installed and migrated by `php artisan auth:install`. Your `User` model must use the `Laravel\Sanctum\HasApiTokens` trait — the installer reminds you of this.

### `throttle:api` — recommended outer bound

**Ships with:** Laravel (`Illuminate\Routing\Middleware\ThrottleRequests`).

**Why it matters.** The package applies `throttle:api` as a coarse per-minute limiter around its public route group, on top of the fine-grained per-action `auth.ratelimit`. If your app has no `api` rate limiter registered (bare package installs / some test harnesses), `AuthServiceProvider` registers a sensible default (60/min) so `throttle:api` never errors. You normally don't touch this.

### The session/cookie stack — required for `web`/`both` mode (SPA cookie auth)

**Ships with:** Laravel (`EncryptCookies`, `AddQueuedCookiesToResponse`, `StartSession`, `ShareErrorsFromSession`).

**Why it matters.** When `auth_system.mode` is `web` or `both`, the package mounts its routes with this session stack (plus the package's own `ConditionalCsrf` in place of Laravel's `VerifyCsrfToken`) so SPA cookie authentication and CSRF protection work. In `api` mode the package uses only the `['api']` group instead. This is automatic in `AuthServiceProvider::registerRoutes()` — listed here only so you recognise it when you set `AUTH_ROUTES_REGISTER=false` and mount the routes yourself (in which case **you** must provide an equivalent stack).

### `role` / `permission` — required for admin routes (Spatie)

See [Third-party (Spatie) aliases](#role-permission-role_or_permission) below. `spatie/laravel-permission` is a hard dependency; the package re-registers its aliases so they're available even on Laravel 11+ where Spatie no longer auto-registers them.

---

## How the package mounts its own routes

`routes/auth.php` already applies these in two groups. You rarely touch them — this section is so you recognise them when reading the package routes.

**Public group** (registration, login, password reset, 2FA challenge):

```php
Route::middleware(['auth.device', 'throttle:api'])->group(function () {
    Route::post('login', ...)->middleware('auth.ratelimit:login');
    Route::post('2fa/challenge', ...)->middleware('auth.ratelimit:otp_verify');
    // …
});
```

**Authenticated group** (me, logout, sessions, 2FA management, trusted devices):

```php
Route::middleware(['auth:sanctum', 'auth.no-refresh', 'auth.verified', 'auth.device'])
    ->group(function () {
        Route::get('me', ...);
        Route::delete('trusted-devices/{id}', ...)->middleware('auth.2fa');
        // …
    });
```

Note the order: `auth:sanctum` (Laravel) authenticates → `auth.no-refresh` rejects refresh tokens → `auth.verified` gates on email → `auth.device` records the device. `auth.2fa` is applied per-route on top of that stack for sensitive actions.

---

## Authentication & identity

### `auth.device` — DeviceFingerprint

**Class:** `Joe404\LaravelAuth\Http\Middleware\DeviceFingerprint`

**What it does.** Runs `DeviceService::fingerprint()` and merges the result onto the request under the `_device` key (`platform`, `browser`, `os`, `device_model`, `device_code`, `fingerprint_hash`, `ip_address`, …). After the response is generated, if a user is authenticated, it calls `SessionService::touch()` to bump `last_active_at` on the session row. It never rejects a request — it is an enricher, not a gate.

**Parameters.** None.

**Where to apply.** On any route that needs device context — both the public auth routes (so login/registration can record the device) and the authenticated routes (so the session row stays warm and the trusted-device + 2FA flows can read the fingerprint). It is safe on every route.

**Reads these client headers.**

- `X-Device-Info` — mobile apps send a structured device descriptor.
- `X-Browser-Fingerprint` — web/SPA sends a client-computed hash (canvas/WebGL/screen/timezone). Validated as hex, length 32–128. **Advisory only** — see the note under [`auth.2fa`](#auth2fa--require2fa) and `docs/upgrading.md` for why fingerprint alone never grants a security decision.

**Failure response.** None — always calls the next middleware.

---

### `auth.api-token` — ApiTokenAuth

**Class:** `Joe404\LaravelAuth\Http\Middleware\ApiTokenAuth`

**What it does.** Authenticates **third-party API tokens** (the `auth_at_{base64}` format issued by the API Token System), _not_ Sanctum session tokens. It:

1. Reads the bearer token; rejects anything not starting with `auth_at_`.
2. Validates it via `ApiTokenService` (existence, not-revoked, not-expired).
3. Optionally checks one or more **abilities** passed as middleware parameters.
4. Merges the token model onto the request as `_api_token` and calls `Auth::setUser()` with the token's owner, so `$request->user()` works downstream.

**Parameters.** Zero or more required abilities, colon-separated:

- `auth.api-token` — any valid token.
- `auth.api-token:read` — token must have the `read` ability.
- `auth.api-token:read:orders` — token must have the `read:orders` ability. (Each ability is one parameter; `auth.api-token:read,write` style is **not** used — pass them as separate segments: `auth.api-token:read:orders` means the single ability string `read:orders`.)

**Where to apply.** On your own application's machine-to-machine / integration endpoints that you want third parties to hit with a long-lived token rather than a user session. This is an alternative authenticator to `auth:sanctum` — you typically use one or the other on a given route, not both.

**Failure responses.**

- `401` — `"API token required."` (missing / wrong prefix), or the typed exception message (revoked, expired), or `"Invalid API token."` (unexpected error; details are logged, never leaked).
- `403` — `"Missing required ability: [<ability>]."`

**Requires.** `auth_system.api_tokens.enabled = true` and the API Token routes/feature. Gate the route group additionally with `auth.feature:api_tokens` if you expose it conditionally.

---

### `auth.no-refresh` — RejectRefreshToken

**Class:** `Joe404\LaravelAuth\Http\Middleware\RejectRefreshToken`

**What it does.** Defense-in-depth: rejects any request whose current access token is actually a refresh token. Refresh tokens live in `auth_refresh_tokens`, not `personal_access_tokens`, so this only catches a legacy Sanctum token named `auth-refresh` from an older install. Prevents a refresh token from being replayed as an access token against protected routes.

**Parameters.** None.

**Where to apply.** Immediately after `auth:sanctum` on every authenticated route. The package already includes it in its authenticated group.

**Failure response.** `401` — `"Unauthenticated."`

---

## Account gating

### `auth.verified` — RequireEmailVerified

**Class:** `Joe404\LaravelAuth\Http\Middleware\RequireEmailVerified`

**What it does.** If the authenticated user's model has `hasVerifiedEmail()` and it returns false, blocks the request. Users with no such method (or already verified) pass through.

**Parameters.** None.

**Where to apply.** On authenticated routes that must not be reachable until email is confirmed. The package's authenticated group includes it. Omit it from routes that _should_ be reachable pre-verification (rare).

**Failure response.** `403` — `"Email address is not verified."`

**Relationship to config.** `auth_system.require_email_verification` controls whether **login** itself is blocked for unverified users. This middleware is the per-route enforcement for everything after login. Use both for a strict policy.

---

### `auth.active` — RequireActiveAccount

**Class:** `Joe404\LaravelAuth\Http\Middleware\RequireActiveAccount`

**What it does.** Reads the user's current account status via `AccountStatusService` and blocks the request if the status is in `auth_system.account.status.login_blocked` (default `disabled`, `suspended`). Because it runs per request, a status change made by an admin takes effect on the user's **very next request** — no need to wait for the token to expire. `deleted` is intentionally not handled here (login auto-restores during grace; after purge the row is gone and the guard rejects naturally).

**Parameters.** None.

**Where to apply.** On authenticated routes where a mid-session ban must take immediate effect — typically the whole authenticated surface of a sensitive app. It is **not** in the package's default authenticated group (status is already checked at login); add it yourself when you need immediate mid-session enforcement.

**Failure response.** `403` with a per-status message (e.g. `"Suspended accounts cannot access this resource."`), overridable via `auth_system.errors.account_suspended` / `account_disabled` or translations.

**Requires.** `auth_system.account.status.enabled = true` (no-ops otherwise).

---

## Step-up / sensitive actions

### `auth.2fa` — Require2FA

**Class:** `Joe404\LaravelAuth\Http\Middleware\Require2FA`

**What it does.** GitHub-style **step-up authentication** for sensitive endpoints. It does not replace login 2FA — it forces a _fresh_ proof of identity even for an already-authenticated session. Decision flow:

1. **Recent 2FA stamp?** If the current session/token completed a 2FA challenge within `auth_system.two_factor.sudo_ttl_minutes` (default 15), pass through.
2. **User has ≥1 verified 2FA method?** Issue (or reuse) a challenge and return `403` with a `challenge_token`. The client completes `POST /auth/2fa/challenge`, then retries the original request.
3. **User has no 2FA enrolled?** Fall back based on `auth_system.two_factor.middleware_behavior`:
   - `block` → `403`, `step_up: enroll_2fa` (client must redirect to enrollment).
   - `force_enroll` → `403`, `step_up: enroll_2fa` (same signal; intended for a "set up 2FA now" modal).
   - `password_confirm` _(default)_ → `403`, `step_up: password_confirm`; the client calls `POST /auth/password/confirm` with the password to get a 15-minute sudo window, then retries.

**Parameters.** None (behavior comes from config).

**Where to apply.** On your own high-risk endpoints — change email, delete account, rotate billing, manage API keys, revoke trusted devices. Always **after** `auth:sanctum`:

```php
Route::middleware(['auth:sanctum', 'auth.2fa'])->group(function () {
    Route::delete('account', ...);
    Route::post('billing/cancel', ...);
});
```

The package itself applies it to `DELETE /auth/trusted-devices` and `DELETE /auth/trusted-devices/{id}`.

**Failure responses (all `403`).** `step_up: two_factor` (with `challenge_token`, `method`, `available_methods`, `expires_in`), `step_up: enroll_2fa`, or `step_up: password_confirm`. A missing user returns `401 "Unauthenticated."`.

**Security note.** Trusted-device 2FA _bypass at login_ requires both the device fingerprint **and** a server-issued `X-Trusted-Device-Token` — fingerprint alone never bypasses. `auth.2fa` step-up is independent of trusted-device status: it always demands a fresh proof regardless of how the session was created.

---

### `auth.step-up` — RequireStepUp

**Class:** `Joe404\LaravelAuth\Http\Middleware\RequireStepUp` *(v2.6.1)*

**What it does.** Config-driven step-up gate for sensitive but non-login actions. Behavior follows `auth_system.two_factor.step_up_mode`:

- `password_confirm` *(default)* — the user must have a fresh sudo window from `POST /auth/password/confirm` (valid for `sudo_ttl_minutes`). Works for users with or without 2FA.
- `two_factor` — the user must pass a fresh 2FA challenge; falls back to `password_confirm` if they have no 2FA method enrolled.

A recent login/step-up 2FA stamp satisfies the gate in either mode, so a user who just completed 2FA isn't asked again within the TTL.

**Parameters.** None (mode comes from config).

**Where it's applied by the package.** Remove a 2FA method (`DELETE /auth/2fa/methods/{id}`), regenerate backup codes, and the phone send/verify endpoints. Admin status changes use it too when `account.status.require_step_up=true` (opt-in). Apply it to your own sensitive actions the same way.

**Failure responses (all `403`).** `step_up: password_confirm`, or `step_up: two_factor` with a `challenge_token` + `method` + `available_methods`.

**`auth.step-up` vs `auth.2fa`.** `auth.2fa` always demands a fresh **2FA** proof (with a `password_confirm`/`force_enroll` fallback only for users with *no* 2FA). `auth.step-up` lets the host choose, via config, whether a password re-entry is sufficient — lighter friction for actions that don't warrant a full second-factor each time.

---

## Feature & mode gating

### `auth.feature` — FeatureFlag

**Class:** `Joe404\LaravelAuth\Http\Middleware\FeatureFlag`

**What it does.** Gates a route group on `config("auth_system.{feature}.enabled")`. Checked at **request time**, not at `route:cache` build time — so toggling the flag after caching routes behaves correctly. When disabled, returns a `404` envelope (the feature looks like it doesn't exist, which is the desired signal for an opt-in feature).

**Parameters.** The config feature key: `auth.feature:api_tokens`, `auth.feature:referral_code`, `auth.feature:two_factor`, `auth.feature:trusted_devices`, `auth.feature:phone`.

**Where to apply.** Wrapping route groups for opt-in features so they 404 cleanly when the host hasn't enabled them. The package uses it for API tokens and referrals; the 2FA/phone/trusted-device controllers do an equivalent in-controller `abort(404)` check.

**Failure response.** `404` — `"Not Found."`

---

### `auth.mode` — AuthMode

**Class:** `Joe404\LaravelAuth\Http\Middleware\AuthMode`

**What it does.** Restricts a route to specific values of `auth_system.mode` (`api`, `web`, `both`). If the current mode isn't in the allow-list, returns `403`.

**Parameters.** One or more allowed modes: `auth.mode:api`, `auth.mode:api,both`.

**Where to apply.** On endpoints that only make sense in one mode — e.g. a token-only endpoint you want unavailable when the app is configured `web`. Niche; most apps never need it.

**Failure response.** `403` — `"This endpoint is not available in the current authentication mode."`

---

### `auth.ratelimit` — RateLimitAuth

**Class:** `Joe404\LaravelAuth\Http\Middleware\RateLimitAuth`

**What it does.** Throttles a route on **two independent keys** — the client IP and the submitted `email` field — using the limits in `auth_system.rate_limits`. If _either_ key is over its limit, the request is rejected. It deliberately does **not** clear the counter on a `2xx` (many auth endpoints return `200` unconditionally to prevent enumeration); counters decay by TTL, and controllers call `RateLimitService::clear()` explicitly on proven success (e.g. correct password on login).

**Parameters.** The config key under `auth_system.rate_limits`: `auth.ratelimit:login`, `auth.ratelimit:register`, `auth.ratelimit:otp_send`, `auth.ratelimit:otp_verify`, `auth.ratelimit:password_reset`. Format of each config value is `"maxAttempts:decayMinutes"`.

**Where to apply.** On unauthenticated, abuse-prone endpoints — login, register, OTP send/verify, password reset, phone OTP, 2FA challenge. The package already applies the right key to each of its routes.

**Failure response.** `429` — the limiter message, with a `Retry-After` header.

**Note.** This is the package's own per-action limiter and is distinct from Laravel's named `throttle:api` limiter (which the package also applies as a coarse outer bound).

---

## Internal / route-stack middleware

### ConditionalCsrf

**Class:** `Joe404\LaravelAuth\Http\Middleware\ConditionalCsrf` (extends Laravel's `VerifyCsrfToken`)

**What it does.** Swapped in for Laravel's CSRF middleware on the package's web/both route stack. It exempts **bearer-token requests** from CSRF (a cross-site page cannot attach an `Authorization` header without a CORS pre-flight, so there's nothing to forge), while still protecting cookie/session (SPA) requests normally. It deliberately does **not** trust `X-Client-Type` as a CSRF signal (any same-origin page can set it).

**Parameters / alias.** No alias — it's wired into the route group automatically by `AuthServiceProvider::registerRoutes()` when `mode` is `web` or `both`. You don't reference it directly.

**Failure response.** Standard Laravel `419` on CSRF token mismatch for cookie-based requests.

---

## Third-party (Spatie) aliases

### `role`, `permission`, `role_or_permission`

Spatie Permission stopped auto-registering its middleware aliases in Laravel 11+. This package **re-registers** them (only if not already present) so the package's admin routes — and yours — can use them without editing `bootstrap/app.php`.

- `role:super-admin|admin` — user must have one of the listed roles.
- `permission:users.manage` — user must have the permission.
- `role_or_permission:admin|users.manage` — either.

**Where to apply.** Admin endpoints. The package's admin routes use `role:` with the role from `auth_system.account.status.admin_ability`, so you can switch the gate to a permission name purely via config.

**Failure response.** Spatie throws `403` (rendered through your exception handler).

---

## Recommended ordering

Middleware runs left-to-right on the way in. The canonical authenticated stack:

```php
Route::middleware([
    'auth:sanctum',     // 1. Laravel authenticates the token/session
    'auth.no-refresh',  // 2. reject refresh tokens used as access tokens
    'auth.verified',    // 3. require verified email
    'auth.active',      // 4. (optional) reject mid-session bans
    'auth.device',      // 5. enrich request with device, touch session
])->group(function () {
    // sensitive routes add step-up on top:
    Route::delete('account', ...)->middleware('auth.2fa');
});
```

Rules of thumb:

- **`auth:sanctum` (or `auth.api-token`) first** — everything downstream calls `$request->user()`.
- **`auth.ratelimit` and `auth.feature` go on the _public_ side**, before any auth, because they gate access to the endpoint itself.
- **`auth.2fa` last and per-route** — it only makes sense once the user is known, and you want it only on the sensitive subset.
- **`auth.device` can go anywhere after auth** but is cheapest placed last; it never rejects.

---

## Recipes

**Protect a sensitive settings endpoint with step-up:**

```php
Route::middleware(['auth:sanctum', 'auth.no-refresh', 'auth.verified', 'auth.2fa'])
    ->post('/account/email', [EmailController::class, 'update']);
```

**Expose a machine-to-machine API guarded by an ability:**

```php
Route::middleware(['auth.feature:api_tokens', 'auth.api-token:read:reports'])
    ->get('/reports/daily', [ReportController::class, 'daily']);
```

**Admin-only, immediate ban enforcement:**

```php
Route::middleware([
    'auth:sanctum', 'auth.verified', 'auth.active',
    'role:' . config('auth_system.account.status.admin_ability'),
])->prefix('admin')->group(function () {
    // …
});
```

**Read the auth context inside a controller (no middleware needed):**

```php
$ctx = $request->authContext();
// ['2fa_enabled' => true, '2fa_verified' => false, 'trust_level' => 'medium',
//  'phone_verified' => true, 'sudo_active' => false]
if (! $ctx['2fa_verified']) {
    // ask for step-up yourself, or rely on the auth.2fa middleware
}
```

---

## See also

- `docs/configuration.md` — every config key these middleware read.
- `docs/upgrading.md` — the v2.6 section explains the 2FA, trusted-device, and phone features end to end.
- `docs/events.md` — events fired by the flows these middleware guard.
