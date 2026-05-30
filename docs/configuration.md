# Configuration Reference

Every key in `config/auth_system.php` — what it does, what values it accepts, which `.env` variable controls it, and the default. Copy-pasteable examples throughout.

Publish the config file so you can edit it locally:

```bash
php artisan vendor:publish --tag=auth-config
```

---

## v2.7+ additions (quick reference)

This page below documents the v2.6 config surface. The keys added across v2.6.1 → v2.7.3 are not yet integrated into the per-section deep-dives — they are listed here as a quick reference with the `.env` variable and default. Full descriptions live in the inline comments of `config/auth_system.php` and in the root [`UPGRADING.md`](../UPGRADING.md) / [`CHANGELOG.md`](../CHANGELOG.md).

### `api_tokens`

| Key | Env | Default | Since |
|-----|-----|---------|-------|
| `mode` | `AUTH_API_TOKENS_MODE` | `customer_auth` | v2.7.1 |
| `grantable_abilities` | — | `['read']` | v2.7.1 |
| `strict_abilities` | `AUTH_API_TOKENS_STRICT` | `false` | v2.7.1 |
| `require_step_up` | `AUTH_API_TOKENS_REQUIRE_STEP_UP` | `false` | v2.7.1 |
| `require_step_up_for_revoke` | `AUTH_API_TOKENS_REQUIRE_STEP_UP_FOR_REVOKE` | `false` | v2.7.3 |
| `admin_require_step_up` | `AUTH_API_TOKENS_ADMIN_REQUIRE_STEP_UP` | `false` | v2.7.1 (POST) / v2.7.2 (PATCH+DELETE) |
| `admin_middleware` | `AUTH_API_TOKENS_ADMIN_MIDDLEWARE` | `null` | v2.7.1 |
| `max_ttl_days` | `AUTH_API_TOKENS_MAX_TTL_DAYS` | `null` | v2.7.1 |

### `account.status`

| Key | Env | Default | Since |
|-----|-----|---------|-------|
| `admin_middleware` | `AUTH_ACCOUNT_STATUS_ADMIN_MIDDLEWARE` | `null` | v2.7.1 |
| `admin_actions.enforce_role_hierarchy` | `AUTH_ACCOUNT_STATUS_HIERARCHY` | `false` | v2.7.1 |
| `admin_actions.allow_self_action` | `AUTH_ACCOUNT_STATUS_ALLOW_SELF` | `false` | v2.7.1 |
| `admin_actions.allow_equal_rank` | `AUTH_ACCOUNT_STATUS_ALLOW_EQUAL` | `false` | v2.7.1 |
| `admin_actions.role_ranks` | — | `['super-admin'=>100,'admin'=>50]` | v2.7.1 |

### `account.deletion`

| Key | Default | Since |
|-----|---------|-------|
| `snapshot_strip_fields` | `null` (uses `response.hidden_user_fields`) | v2.7.3 |

### `password_reset`

| Key | Env | Default | Since |
|-----|-----|---------|-------|
| `auto_login` | `AUTH_PASSWORD_RESET_AUTO_LOGIN` | `true` | v2.7.1 |

### `social`

| Key | Env | Default | Since |
|-----|-----|---------|-------|
| `enforce_state` | `AUTH_SOCIAL_ENFORCE_STATE` | `false` | v2.7.1 |

### `security`

| Key | Env | Default | Since |
|-----|-----|---------|-------|
| `profile` | `AUTH_SECURITY_PROFILE` | `null` (`relaxed`\|`balanced`\|`high`) | v2.7.1 |
| `lockout.scope` | `AUTH_LOCKOUT_SCOPE` | `email` (`ip`\|`email_and_ip`) | v2.7.1 |
| `lockout.backoff` | `AUTH_LOCKOUT_BACKOFF` | `false` | v2.7.1 |

### `trusted_devices`

| Key | Env | Default | Since |
|-----|-----|---------|-------|
| `registration_device_level` | `AUTH_TRUST_REG_DEVICE_LEVEL` | `high` | v2.7.1 |

### `response`

| Key | Default | Since |
|-----|---------|-------|
| `hidden_user_fields` | `['password','remember_token']` | v2.7.1 |

### `referral_code`

| Key | Env | Default | Since |
|-----|-----|---------|-------|
| `admin_ability` | `AUTH_REFERRAL_ADMIN_ABILITY` | `'super-admin\|admin'` | v2.7.3 |
| `admin_middleware` | `AUTH_REFERRAL_ADMIN_MIDDLEWARE` | `null` | v2.7.3 |

### Security profile mapping

`AUTH_SECURITY_PROFILE=high` flips on every hardening flag the library exposes (unless the corresponding env var is already set, in which case the env value always wins):

- `api_tokens.strict_abilities = true`
- `api_tokens.require_step_up = true`
- `api_tokens.require_step_up_for_revoke = true` (v2.7.3)
- `api_tokens.admin_require_step_up = true`
- `social.enforce_state = true`
- `security.lockout.scope = email_and_ip`
- `password_reset.auto_login = false`
- `account.status.admin_actions.enforce_role_hierarchy = true`
- `account.status.require_step_up = true`
- `trusted_devices.registration_device_level = medium`
- `two_factor.required = true`

`AUTH_SECURITY_PROFILE=balanced` enables only `strict_abilities`, `enforce_state`, and `lockout.scope=email_and_ip`. `relaxed` (or unset) is a no-op.

---

## Table of Contents

1. [mode](#1-mode)
2. [spa_token](#2-spa_token)
3. [require_email_verification](#3-require_email_verification)
4. [routes](#4-routes)
5. [registration](#5-registration)
6. [referral_code](#6-referral_code)
7. [verification](#7-verification)
8. [password_reset](#8-password_reset)
9. [password](#9-password)
10. [token_ttl](#10-token_ttl)
11. [rate_limits](#11-rate_limits)
12. [roles](#12-roles)
13. [otp_channel](#13-otp_channel)
14. [mail](#14-mail)
15. [social](#15-social)
16. [reverb](#16-reverb)
17. [api_tokens](#17-api_tokens)
18. [queue](#18-queue)
19. [response](#19-response)
20. [security](#20-security)
21. [account.status](#21-accountstatus)
22. [account.deletion](#22-accountdeletion)
23. [account.deactivation](#23-accountdeactivation)
24. [account.audit](#24-accountaudit)
25. [phone (v2.6)](#25-phone-v26)
26. [two_factor (v2.6)](#26-two_factor-v26)
27. [trusted_devices (v2.6)](#27-trusted_devices-v26)
28. [messages](#28-messages)
29. [errors](#29-errors)
30. [Complete .env reference](#30-complete-env-reference)

---

## 1. `mode`

**Env:** `AUTH_MODE` | **Default:** `both`

Controls what credential type the server issues after a successful login.

| Value | Behaviour |
|---|---|
| `api` | Always returns a Bearer token. Best for pure API backends, mobile apps. |
| `web` | Always uses a Laravel session cookie. Best for server-rendered apps. |
| `both` | Auto-detects per request — see detection order below. |

**Detection order for `both` mode** (first match wins):

1. Request has `X-Client-Type: mobile` header → Bearer token (mobile TTL)
2. `spa_token = true` and no `X-Client-Type` → Bearer token (SPA TTL)
3. Everything else → session cookie (no token)

```env
AUTH_MODE=both
```

---

## 2. `spa_token`

**Env:** `AUTH_SPA_TOKEN` | **Default:** `false`

Only applies when `AUTH_MODE=both`.

- `false` — browser SPA clients get a session cookie (recommended, most secure)
- `true` — browser SPA clients get a Bearer token instead (same as mobile clients)

```env
AUTH_SPA_TOKEN=false
```

---

## 3. `require_email_verification`

**Env:** `AUTH_REQUIRE_VERIFICATION` | **Default:** `true`

- `true` — users who have not verified their email address cannot log in; login returns HTTP 403
- `false` — users can log in immediately after registering without verifying their email; useful for internal tools or dev environments

```env
AUTH_REQUIRE_VERIFICATION=true
```

---

## 4. `routes`

Controls how and where the package mounts its HTTP routes.

### `routes.register`

**Env:** `AUTH_ROUTES_REGISTER` | **Default:** `true`

- `true` — routes auto-mount under the configured prefix and middleware at boot
- `false` — the package does NOT register routes; you must include the route file yourself

**When to use `false`:** when you need the endpoints inside an existing versioned `Route::prefix('api/v2')` group with your own middleware ordering.

```php
// routes/api.php (manual mount example)
Route::prefix('api/v1/auth')
    ->middleware(['api', 'throttle:api'])
    ->group(base_path('vendor/joe-404/laravel-auth/routes/auth.php'));
```

### `routes.prefix`

**Env:** `AUTH_ROUTES_PREFIX` | **Default:** `auth`

The URL prefix for all package routes. With the default, routes are at `/auth/login`. To use versioned URLs set this to `api/v1/auth` and they become `/api/v1/auth/login`.

```env
AUTH_ROUTES_PREFIX=auth           # → /auth/login, /auth/register
AUTH_ROUTES_PREFIX=api/v1/auth    # → /api/v1/auth/login, /api/v1/auth/register
```

### `routes.middleware`

**Default:** `null` (package picks automatically based on `mode`)

When `null`:
- `api` mode → `['api']`
- `web` / `both` mode → session + cookie + CSRF + `['api']`

Override completely by setting an array:

```php
// config/auth_system.php
'routes' => [
    'middleware' => ['api', 'my-custom-throttle'],
],
```

---

## 5. `registration`

Options that extend what data users can submit during registration.

### `extra_fields_rules`

**Default:** `[]`

A map of `field_name => validation_rules`. These fields are validated alongside `email` on `POST /auth/register` and are written to `User::create()` when registration is finalized.

Rules can be a **pipe-separated string:**

```php
'extra_fields_rules' => [
    'phone'   => 'nullable|string|max:20',
    'country' => 'required|string|size:2',
],
```

Or an **array** (required when using object rule classes):

```php
'extra_fields_rules' => [
    'username'      => ['required', 'string', 'min:3', 'max:30', 'unique:users,username'],
    'date_of_birth' => ['required', 'date', 'before:18 years ago'],
    'agreed_terms'  => ['required', 'accepted'],
    'agreed_18_plus'=> ['required', 'accepted'],
],
```

**Important:** every field listed here must be in your `User` model's `$fillable`, otherwise `User::create()` silently ignores it.

### `extra_fields_messages`

**Default:** `[]`

Custom error messages for extra field validation. Standard Laravel `field.rule` format.

```php
'extra_fields_messages' => [
    'username.required'      => 'Please choose a username.',
    'username.unique'        => 'That username is already taken.',
    'username.min'           => 'Username must be at least 3 characters.',
    'agreed_terms.accepted'  => 'You must accept our Terms of Service to continue.',
    'date_of_birth.before'   => 'You must be at least 18 years old to register.',
],
```

Any key not listed here falls back to Laravel's built-in message.

### `extra_fields_transformers`

**Default:** `[]`

Derive or normalise a column value from the validated registration data — without writing a custom controller. The key is the target column name, the value is a class implementing `ExtraFieldTransformerContract`.

```php
'extra_fields_transformers' => [
    'username_normalized' => \App\Transformers\UsernameNormalizer::class,
],
```

The transformer runs after validation passes and before `User::create()`. The result is written to the target column. See [docs/customization.md](customization.md#extra-field-transformers) for the full contract and examples.

**Security note:** transformers cannot bypass the built-in privileged-field denylist. These target names are always stripped, even from transformer output: `role`, `roles`, `is_admin`, `admin`, `email_verified_at`, `password`, `password_change_required`.

### `request_class`

**Default:** `null`

Override the built-in `RegisterRequest` with your own `FormRequest` subclass — for complex conditional rules, custom messages, or validation logic that can't be expressed as rule strings.

```php
'registration' => [
    'request_class' => \App\Http\Requests\MyRegisterRequest::class,
],
```

`request_class` takes priority over `extra_fields_rules` when both are set. See [docs/customization.md](customization.md#custom-register-request) for the subclassing example.

---

## 6. `referral_code`

**Env:** multiple | **Default:** all off

When enabled, generates a unique referral code per user during registration and writes it to the configured column.

```php
'referral_code' => [
    'enabled'   => env('AUTH_REFERRAL_CODE_ENABLED', false),   // master switch
    'column'    => env('AUTH_REFERRAL_CODE_COLUMN', 'referral_code'),
    'length'    => env('AUTH_REFERRAL_CODE_LENGTH', 10),
    'uppercase' => env('AUTH_REFERRAL_CODE_UPPERCASE', true),
    'generator' => env('AUTH_REFERRAL_CODE_GENERATOR', null),  // FQCN or null
],
```

| Key | Effect |
|---|---|
| `enabled` | `false` (default) = nothing happens. `true` = generate a code for every new user. |
| `column` | The `users` table column that stores the code. Must be in `$fillable` and your migration. |
| `length` | Number of characters in the generated code. Default: 10. |
| `uppercase` | `true` = code is all uppercase (default). `false` = mixed case. |
| `generator` | FQCN of a class implementing `ReferralCodeGeneratorContract`. Leave `null` to use the default random alphanumeric generator. |

**Will not overwrite:** if the user already supplied a value for the referral column via `extra_fields_rules`, the package will not overwrite it.

**Required migration when enabling:**

```php
Schema::table('users', function (Blueprint $table): void {
    $table->string('referral_code', 20)->nullable()->unique();
});
```

See [docs/customization.md](customization.md#referral-codes) for the custom generator contract.

---

## 7. `verification`

Controls how users verify their email address after registration.

```php
'verification' => [
    'method'              => env('AUTH_VERIFICATION_METHOD', 'both'),
    'otp_length'          => env('AUTH_OTP_LENGTH', 6),
    'otp_expiry'          => env('AUTH_OTP_EXPIRY', 10),
    'otp_max_attempts'    => env('AUTH_OTP_MAX_ATTEMPTS', 5),
    'magic_expiry'        => env('AUTH_MAGIC_EXPIRY', 30),
    'magic_link_target'   => env('AUTH_MAGIC_LINK_TARGET', 'backend'),
    'frontend_verify_url' => env('AUTH_FRONTEND_VERIFY_URL', null),
    'frontend_reset_url'  => env('AUTH_FRONTEND_RESET_URL', null),
],
```

| Key | Env | Default | Description |
|---|---|---|---|
| `method` | `AUTH_VERIFICATION_METHOD` | `both` | `otp` = numeric code only; `magic_link` = clickable link only; `both` = one email with OTP + link simultaneously |
| `otp_length` | `AUTH_OTP_LENGTH` | `6` | Number of digits in the OTP code (4–8) |
| `otp_expiry` | `AUTH_OTP_EXPIRY` | `10` | Minutes the OTP is valid before it expires |
| `otp_max_attempts` | `AUTH_OTP_MAX_ATTEMPTS` | `5` | Wrong guesses before the OTP is invalidated (brute-force guard) |
| `magic_expiry` | `AUTH_MAGIC_EXPIRY` | `30` | Minutes the magic link is valid |
| `magic_link_target` | `AUTH_MAGIC_LINK_TARGET` | `backend` | `backend` = link points to Laravel API; `frontend` = link points to your SPA/app, which then calls the API itself |
| `frontend_verify_url` | `AUTH_FRONTEND_VERIFY_URL` | `null` | Required when `magic_link_target=frontend`. Your SPA URL for email verification. The package appends `?token=xxx`. |
| `frontend_reset_url` | `AUTH_FRONTEND_RESET_URL` | `null` | Required when `magic_link_target=frontend`. Your SPA URL for password reset. |

**Frontend magic link flow** (when `magic_link_target=frontend`):

```
Email link → https://myapp.com/verify-email?token=xxx
    ↓
SPA extracts token from URL
    ↓
SPA calls GET /auth/register/verify-magic/{token}
    ↓
API returns { completion_token: "..." }
```

---

## 8. `password_reset`

Controls how password reset codes or links are delivered.

```php
'password_reset' => [
    'method' => env('AUTH_PASSWORD_RESET_METHOD', null),
],
```

| Value | Effect |
|---|---|
| `null` (default) | Inherit from `verification.method` |
| `otp` | Send a numeric code only |
| `magic_link` | Send a clickable link only |
| `both` | Send one email with OTP + link |

**Example:** your app uses magic links for registration but you prefer OTP codes for the reset form (easier to type on mobile):

```env
AUTH_VERIFICATION_METHOD=magic_link
AUTH_PASSWORD_RESET_METHOD=otp
```

---

## 9. `password`

Password policy enforced when users register or change their password.

```php
'password' => [
    'min_length'          => env('AUTH_PASSWORD_MIN', 8),
    'require_uppercase'   => env('AUTH_PASSWORD_UPPERCASE', false),
    'require_number'      => env('AUTH_PASSWORD_NUMBER', false),
    'require_special'     => env('AUTH_PASSWORD_SPECIAL', false),
    'pending_ttl_minutes' => env('AUTH_PENDING_TTL', 60),
],
```

| Key | Env | Default | Description |
|---|---|---|---|
| `min_length` | `AUTH_PASSWORD_MIN` | `8` | Minimum number of characters |
| `require_uppercase` | `AUTH_PASSWORD_UPPERCASE` | `false` | Require at least one capital letter (A–Z) |
| `require_number` | `AUTH_PASSWORD_NUMBER` | `false` | Require at least one digit (0–9) |
| `require_special` | `AUTH_PASSWORD_SPECIAL` | `false` | Require at least one symbol (`!@#$%...`) |
| `pending_ttl_minutes` | `AUTH_PENDING_TTL` | `60` | Minutes the pending registration is cached (between step 1 "initiate" and step 3 "complete"). If the user doesn't finish within this window, they must restart. |

**Recommended production policy:**

```env
AUTH_PASSWORD_MIN=10
AUTH_PASSWORD_UPPERCASE=true
AUTH_PASSWORD_NUMBER=true
AUTH_PASSWORD_SPECIAL=true
```

---

## 10. `token_ttl`

How long access tokens and refresh tokens stay valid, broken out by client type.

```php
'token_ttl' => [
    'mobile' => [
        'access_minutes'  => env('AUTH_TOKEN_TTL_MOBILE', 10080),    // 7 days
        'refresh_minutes' => env('AUTH_REFRESH_TTL_MOBILE', 43200),  // 30 days
    ],
    'spa' => [
        'access_minutes'  => env('AUTH_TOKEN_TTL_SPA', 1440),        // 24 hours
        'refresh_minutes' => env('AUTH_REFRESH_TTL_SPA', 10080),     // 7 days
    ],
    'api' => [
        'access_minutes'  => env('AUTH_TOKEN_TTL_API', 525600),      // 365 days
        'refresh_minutes' => env('AUTH_REFRESH_TTL_API', 0),         // 0 = never expires
    ],
    'web' => [
        'session_minutes' => env('AUTH_SESSION_TTL', 120),           // keep in sync with SESSION_LIFETIME
    ],
],
```

| Client type | How it's detected |
|---|---|
| `mobile` | Login request has `X-Client-Type: mobile` header |
| `spa` | `AUTH_MODE=both` and `AUTH_SPA_TOKEN=true` |
| `api` | `AUTH_MODE=api` |
| `web` | `AUTH_MODE=web` or session mode in `both` |

Setting `access_minutes` or `refresh_minutes` to `0` means the token never expires (not recommended for short-lived clients).

---

## 11. `rate_limits`

Rate limits applied per IP address and per email address independently. Exceeding either returns HTTP 429.

```php
'rate_limits' => [
    'register'       => env('AUTH_RATE_REGISTER', '5:1'),
    'login'          => env('AUTH_RATE_LOGIN', '5:1'),
    'otp_send'       => env('AUTH_RATE_OTP_SEND', '3:1'),
    'otp_verify'     => env('AUTH_RATE_OTP_VERIFY', '10:5'),
    'password_reset' => env('AUTH_RATE_PASSWORD_RESET', '3:1'),
],
```

Format: `"max_attempts:decay_minutes"` — e.g. `"5:1"` = 5 attempts per 1 minute.

| Key | Endpoint protected | Default |
|---|---|---|
| `register` | `POST /auth/register` | `5:1` |
| `login` | `POST /auth/login` | `5:1` |
| `otp_send` | `POST /auth/email/resend-verification` | `3:1` |
| `otp_verify` | `POST /auth/register/verify-otp` | `10:5` |
| `password_reset` | `POST /auth/password/forgot` | `3:1` |

**Stricter production example:**

```env
AUTH_RATE_LOGIN=3:5
AUTH_RATE_PASSWORD_RESET=2:10
```

---

## 12. `roles`

```php
'roles' => [
    'default_role' => env('AUTH_DEFAULT_ROLE', 'user'),
    'seeded_roles' => ['super-admin', 'admin', 'user'],
],
```

| Key | Description |
|---|---|
| `default_role` | Role automatically assigned to every new user after they verify their email. The role must exist — run `AuthRolesSeeder` first. |
| `seeded_roles` | Roles that `AuthRolesSeeder` creates. Add any custom roles your app needs here. |

```env
AUTH_DEFAULT_ROLE=member
```

**To add a custom role:**

```php
// config/auth_system.php
'roles' => [
    'default_role' => 'fan',
    'seeded_roles' => ['super-admin', 'admin', 'fan', 'creator'],
],
```

Then re-run the seeder: `php artisan db:seed --class="Joe404\LaravelAuth\Database\Seeders\AuthRolesSeeder"`

---

## 13. `otp_channel`

**Env:** `AUTH_OTP_CHANNEL` | **Default:** `email`

Controls how OTP codes and magic links are delivered.

- `email` — built-in email delivery (default)
- A FQCN — your own class implementing `OtpChannelContract` (SMS, WhatsApp, push notification, etc.)

```php
'otp_channel' => [
    'driver' => env('AUTH_OTP_CHANNEL', 'email'),
    // or:
    'driver' => \App\Channels\SmsOtpChannel::class,
],
```

See [docs/customization.md](customization.md#custom-otp-channel) for the full contract and examples.

---

## 14. `mail`

Controls which notification classes are used for each email, and which account lifecycle emails are enabled.

### Email notification overrides

Each key accepts `null` (use the built-in) or a FQCN of your own Notification class.

```php
'mail' => [
    // Registration / password reset emails
    'otp_verify_notification'          => null,
    'otp_reset_notification'           => null,
    'magic_link_verify_notification'   => null,
    'magic_link_reset_notification'    => null,
    'otp_verify_combined_notification' => null,
    'otp_reset_combined_notification'  => null,

    // Account lifecycle emails (v2.4)
    'account_deleted_notification'         => null,
    'account_restored_notification'        => null,
    'account_purged_notification'          => null,
    'account_status_changed_notification'  => null,
    'account_deactivated_notification'     => null,
    'account_reactivated_notification'     => null,

    // Toggle which lifecycle emails are sent
    'account_notifications_enabled' => [
        'deleted'        => true,
        'restored'       => true,
        'purged'         => false,   // off by default (background worker action)
        'status_changed' => false,   // off by default (not always user-facing)
        'deactivated'    => true,
        'reactivated'    => true,
    ],
],
```

**Custom notification constructor signature:**

For OTP/magic-link notifications, your class constructor receives:
- `($code, $type, $context)` for single-delivery
- `($code, $url, $type, $context)` for combined

**Alternative: Blade view override (no PHP needed)**

```bash
php artisan vendor:publish --tag=auth-views
```

Editable templates appear in `resources/views/vendor/laravel-auth/emails/`:

| File | Email sent for |
|---|---|
| `otp-verify.blade.php` | OTP code during registration |
| `otp-reset.blade.php` | OTP code for password reset |
| `magic-link-verify.blade.php` | Magic link during registration |
| `magic-link-reset.blade.php` | Magic link for password reset |
| `otp-verify-combined.blade.php` | OTP + link in one email (verification, method=both) |
| `otp-reset-combined.blade.php` | OTP + link in one email (password reset, method=both) |

Custom notification class takes priority over the Blade view for the same email slot.

---

## 15. `social`

```php
'social' => [
    'google' => [
        'enabled'       => env('AUTH_GOOGLE_ENABLED', false),
        'client_id'     => env('AUTH_GOOGLE_CLIENT_ID'),
        'client_secret' => env('AUTH_GOOGLE_CLIENT_SECRET'),
        'redirect'      => env('AUTH_GOOGLE_REDIRECT'),
    ],
    'frontend_url' => env('AUTH_SOCIAL_FRONTEND_URL', null),
],
```

| Key | Env | Description |
|---|---|---|
| `google.enabled` | `AUTH_GOOGLE_ENABLED` | Master switch for Google OAuth |
| `google.client_id` | `AUTH_GOOGLE_CLIENT_ID` | From Google Cloud Console |
| `google.client_secret` | `AUTH_GOOGLE_CLIENT_SECRET` | From Google Cloud Console |
| `google.redirect` | `AUTH_GOOGLE_REDIRECT` | Must match the URI registered in Google Cloud Console |
| `frontend_url` | `AUTH_SOCIAL_FRONTEND_URL` | After a social account-link confirmation email is clicked, where to redirect. `null` = return JSON instead of redirecting. |
| `profile_completion.enabled` *(v2.6)* | `AUTH_SOCIAL_PROFILE_COMPLETION` | When true, a brand-new Google user who is missing the host's required registration fields is NOT created/logged-in immediately. The callback returns `requires_profile_completion` + a `completion_token`; the frontend collects the required fields and POSTs them to `/auth/social/complete`. Default `false` (legacy behavior: create + log in from the Google profile alone). |
| `profile_completion.ttl_minutes` *(v2.6)* | `AUTH_SOCIAL_PROFILE_COMPLETION_TTL` | Minutes the completion token stays valid. Default `15`. |

> **Why this exists.** OAuth supplies identity (email, name) but never your app's custom fields (username, phone, country…). With `profile_completion` enabled, the social path enforces the **same** `registration.extra_fields_rules` + phone rules as the email flow — no user row is created until the required fields are submitted, so an abandoned onboarding leaves nothing behind. Only `required` fields block; optional ones can be filled later. Phone is captured here and verified afterward via the normal `/auth/phone` flow.

**Setup steps:**
1. Go to [console.cloud.google.com](https://console.cloud.google.com)
2. Create a project → Credentials → OAuth 2.0 Client ID → Web application
3. Add your redirect URI: `https://yourapp.com/auth/social/google/callback`
4. Copy the Client ID and Client Secret to `.env`

```env
AUTH_GOOGLE_ENABLED=true
AUTH_GOOGLE_CLIENT_ID=123456789.apps.googleusercontent.com
AUTH_GOOGLE_CLIENT_SECRET=GOCSPX-xxxx
AUTH_GOOGLE_REDIRECT=https://yourapp.com/auth/social/google/callback
AUTH_SOCIAL_FRONTEND_URL=https://yourapp.com/auth/callback
```

---

## 16. `reverb`

**Env:** `AUTH_REVERB_ENABLED` | **Default:** `false`

When enabled, the package broadcasts an event on the private channel `auth.verification.{tempToken}` as soon as a user's email is verified. Your frontend can subscribe to this channel with Laravel Echo and react immediately without polling.

Requires `laravel/reverb` to be installed and configured.

```env
AUTH_REVERB_ENABLED=true
```

**Frontend subscription example:**

```js
Echo.private(`auth.verification.${tempToken}`)
    .listen('EmailVerified', (e) => {
        // Verification complete — proceed to completion step
        showPasswordForm(e.completionToken);
    });
```

---

## 17. `api_tokens`

Long-lived, scoped tokens for third-party integrations. Disabled by default.

```php
'api_tokens' => [
    'enabled'           => env('AUTH_API_TOKENS_ENABLED', false),
    'abilities_default' => ['read'],
],
```

| Key | Description |
|---|---|
| `enabled` | `false` (default) = API token routes don't exist, no cleanup job runs. `true` = enables user and admin API token endpoints, and schedules `CleanExpiredApiTokens` hourly. |
| `abilities_default` | Default abilities assigned when creating a token without specifying abilities. |

These tokens use the format `auth_at_{base64}`, are stored in `auth_api_tokens`, and are completely separate from Sanctum session tokens.

```env
AUTH_API_TOKENS_ENABLED=true
```

---

## 18. `queue`

```php
'queue' => [
    'connection' => env('AUTH_QUEUE_CONNECTION', null),
    'name'       => env('AUTH_QUEUE_NAME', 'auth-maintenance'),
],
```

| Key | Description |
|---|---|
| `connection` | Queue connection (`redis`, `database`, `sqs`, etc.). `null` = use the app's default. |
| `name` | Queue name for maintenance jobs. Run a worker: `php artisan queue:work --queue=auth-maintenance` |

**Background jobs the package runs automatically:**

| Job | Frequency | Always active? |
|---|---|---|
| `CleanExpiredOtpRecords` | Every 5 minutes | Yes |
| `CleanExpiredRefreshTokens` | Hourly | Yes |
| `PurgeExpiredAccountDeletions` | Hourly | Yes (when deletion.enabled=true) |
| `CleanExpiredApiTokens` | Hourly | Only when `api_tokens.enabled=true` |

---

## 19. `response`

**Env:** `AUTH_RESPONSE_FORMATTER` | **Default:** `null` (built-in format)

Swap the JSON envelope to match your API conventions.

```php
'response' => [
    'formatter' => env('AUTH_RESPONSE_FORMATTER', null),
    // or directly:
    'formatter' => \App\Auth\MyFormatter::class,
],
```

Your formatter class must implement `ResponseFormatterContract`:

```php
// app/Auth/MyFormatter.php
use Joe404\LaravelAuth\Contracts\ResponseFormatterContract;

class MyFormatter implements ResponseFormatterContract
{
    public function format(bool $success, string $message, array $data, array $errors): array
    {
        return [
            'ok'      => $success,
            'msg'     => $message,
            'payload' => $data ?: $errors,
        ];
    }
}
```

Alternatively register via the service container (config takes priority):

```php
// app/Providers/AppServiceProvider.php
use Joe404\LaravelAuth\Contracts\ResponseFormatterContract;

$this->app->bind(ResponseFormatterContract::class, \App\Auth\MyFormatter::class);
```

See [docs/customization.md](customization.md#custom-response-formatter) for full details.

---

## 20. `security`

```php
'security' => [
    'notify_new_device_login' => env('AUTH_NOTIFY_NEW_DEVICE', true),
    'lockout' => [
        'enabled'       => env('AUTH_LOCKOUT_ENABLED', true),
        'max_attempts'  => env('AUTH_LOCKOUT_MAX', 10),
        'decay_minutes' => env('AUTH_LOCKOUT_DECAY', 15),
    ],
],
```

| Key | Env | Default | Description |
|---|---|---|---|
| `notify_new_device_login` | `AUTH_NOTIFY_NEW_DEVICE` | `true` | Send an email alert when a user logs in from a device (browser + OS combo) the package hasn't seen before |
| `lockout.enabled` | `AUTH_LOCKOUT_ENABLED` | `true` | Temporarily lock an account after too many failed logins |
| `lockout.max_attempts` | `AUTH_LOCKOUT_MAX` | `10` | Failed login count before lockout triggers |
| `lockout.decay_minutes` | `AUTH_LOCKOUT_DECAY` | `15` | How long the lockout lasts in minutes |

**Note:** lockout is separate from rate limiting. Rate limiting blocks by request speed; lockout blocks by total failure count accumulated over time.

---

## 21. `account.status`

Controls the account status system (active / suspended / disabled / deactivated / deleted).

```php
'account' => [
    'status' => [
        'enabled'                   => env('AUTH_ACCOUNT_STATUS_ENABLED', true),
        'column'                    => env('AUTH_ACCOUNT_STATUS_COLUMN', 'account_status'),
        'default'                   => env('AUTH_ACCOUNT_STATUS_DEFAULT', 'active'),
        'allowed'                   => ['active', 'disabled', 'suspended', 'deleted', 'deactivated'],
        'login_blocked'             => ['disabled', 'suspended'],
        'login_auto_restorable'     => ['deactivated'],
        'revoke_sessions_on_change' => env('AUTH_ACCOUNT_STATUS_REVOKE_ON_CHANGE', true),
        'admin_ability'             => env('AUTH_ACCOUNT_STATUS_ABILITY', 'super-admin|admin'),
        'auto_unban' => [
            'enabled'            => env('AUTH_ACCOUNT_AUTO_UNBAN', true),
            'sweep_minutes'      => env('AUTH_ACCOUNT_AUTO_UNBAN_SWEEP', 5),
            'temporary_statuses' => ['suspended'],
        ],
    ],
],
```

| Key | Description |
|---|---|
| `enabled` | Master switch. `false` = login and middleware skip all status checks. |
| `column` | The `users` table column that stores the status string. |
| `default` | Status for brand-new users. |
| `allowed` | Accepted status values. Add custom ones here (e.g. `pending_review`). |
| `login_blocked` | Statuses that block login with an error. `deleted` is not here — it's handled by the deletion flow. |
| `login_auto_restorable` | Statuses that auto-flip back to `active` on a successful login. `deactivated` is here by default (Instagram-style: log in and you're back). |
| `revoke_sessions_on_change` | When a status changes away from `active`, revoke all Sanctum tokens and session rows. |
| `admin_ability` | The Spatie role/permission required to call the admin status endpoints. Pipe-separated = any match wins. |
| `auto_unban.enabled` | Enable the timed-ban system (lazy revert + scheduled sweep). |
| `auto_unban.sweep_minutes` | How often the sweep worker runs to revert expired bans. |
| `auto_unban.temporary_statuses` | Statuses that can carry an expiry. Not listed here = permanent-only (passing `expires_at` returns 422). |

**Adding a custom status:**

```php
'allowed'       => ['active', 'disabled', 'suspended', 'deleted', 'deactivated', 'pending_review'],
'login_blocked' => ['disabled', 'suspended', 'pending_review'],
```

```php
// lang/vendor/auth_system/en/errors.php (host-published copy)
'account_pending_review' => 'Your account is under review. We will email you shortly.',
```

The error key is always `account_{status}`.

See [docs/account-status.md](account-status.md) for the complete guide.

---

## 22. `account.deletion`

Controls self-service account deletion with a grace period.

```php
'account' => [
    'deletion' => [
        'enabled'                  => env('AUTH_ACCOUNT_DELETE_ENABLED', true),
        'self_service'             => env('AUTH_ACCOUNT_DELETE_SELF', true),
        'require_password'         => env('AUTH_ACCOUNT_DELETE_REQUIRE_PASSWORD', true),
        'grace_days'               => env('AUTH_ACCOUNT_DELETE_GRACE_DAYS', 30),
        'auto_restore_on_login'    => env('AUTH_ACCOUNT_AUTO_RESTORE', true),
        'null_uniques_after_grace' => env('AUTH_ACCOUNT_NULL_UNIQUES', true),
        'hard_delete_after_grace'  => env('AUTH_ACCOUNT_HARD_DELETE', false),
        'move_to_deleted_table'    => env('AUTH_ACCOUNT_AUDIT_TABLE', true),
        'unique_columns'           => env('AUTH_ACCOUNT_UNIQUE_COLUMNS', 'auto'),
        'unique_exclude'           => ['id'],
    ],
],
```

| Key | Default | Description |
|---|---|---|
| `enabled` | `true` | Master switch for account deletion. |
| `self_service` | `true` | Expose `DELETE /auth/account` for users. `false` = only admins can delete via status endpoint. |
| `require_password` | `true` | Require the user's password on the delete call. Strongly recommended. |
| `grace_days` | `30` | Days the account stays restorable before the purge worker runs. |
| `auto_restore_on_login` | `true` | Login during grace silently restores the account (recommended). |
| `null_uniques_after_grace` | `true` | After grace, null unique columns (email, username) so they can be reclaimed by a new signup. |
| `hard_delete_after_grace` | `false` | After grace, hard-delete the `users` row entirely. The `deleted_accounts` snapshot is kept for audit. |
| `move_to_deleted_table` | `true` | Snapshot the full `users` row to `deleted_accounts` at delete time. |
| `unique_columns` | `auto` | `'auto'` = introspect schema for unique indexes. Or pass an explicit array: `['email', 'username']`. |
| `unique_exclude` | `['id']` | Columns the resolver must never null (primary keys, etc.). |

**Required:** `users` unique columns must be nullable in your migration:

```php
$table->string('email')->nullable()->unique();
$table->string('username')->nullable()->unique();
```

See [docs/account-deletion.md](account-deletion.md) for the full flow.

---

## 23. `account.deactivation`

Controls Instagram-style self-service account pause.

```php
'account' => [
    'deactivation' => [
        'enabled'                  => env('AUTH_ACCOUNT_DEACTIVATE_ENABLED', true),
        'self_service'             => env('AUTH_ACCOUNT_DEACTIVATE_SELF', true),
        'require_password'         => env('AUTH_ACCOUNT_DEACTIVATE_REQUIRE_PASSWORD', true),
        'auto_reactivate_on_login' => env('AUTH_ACCOUNT_AUTO_REACTIVATE', true),
    ],
],
```

| Key | Default | Description |
|---|---|---|
| `enabled` | `true` | Master switch. |
| `self_service` | `true` | Expose `POST /auth/account/deactivate`. |
| `require_password` | `true` | Require the user's password on the deactivate call. |
| `auto_reactivate_on_login` | `true` | When `true`, a successful login auto-flips `deactivated` back to `active`. No separate reactivate endpoint. |

**If you want to require a support ticket to come back:** set `auto_reactivate_on_login=false` and add `deactivated` to `account.status.login_blocked`.

---

## 24. `account.audit`

Controls the account status audit log written to `account_status_logs`.

```php
'account' => [
    'audit' => [
        'enabled'              => env('AUTH_ACCOUNT_AUDIT_ENABLED', true),
        'table'                => env('AUTH_ACCOUNT_AUDIT_TABLE_NAME', 'account_status_logs'),
        'log_status_changes'   => env('AUTH_ACCOUNT_AUDIT_LOG_STATUS', true),
        'log_system_actions'   => env('AUTH_ACCOUNT_AUDIT_LOG_SYSTEM', true),
        'capture_request_meta' => env('AUTH_ACCOUNT_AUDIT_CAPTURE_META', true),
        'retention_days'       => null,
        'notes' => [
            'enabled' => env('AUTH_ACCOUNT_AUDIT_NOTES_ENABLED', true),
        ],
        'history' => [
            'enabled'          => env('AUTH_ACCOUNT_AUDIT_HISTORY_ENABLED', true),
            'default_per_page' => env('AUTH_ACCOUNT_AUDIT_HISTORY_PER_PAGE', 20),
            'max_per_page'     => env('AUTH_ACCOUNT_AUDIT_HISTORY_MAX_PER_PAGE', 100),
        ],
    ],
],
```

| Key | Default | Description |
|---|---|---|
| `enabled` | `true` | Master switch. `false` = nothing is logged, both admin endpoints return 404. |
| `table` | `account_status_logs` | Table name. Override if you already use this name. |
| `log_status_changes` | `true` | Log status transitions (admin, user, automatic). `false` = only admin notes are logged. |
| `log_system_actions` | `true` | Include automatic actions (lazy revert, sweep worker, login auto-restore, purge). `false` = only human-initiated actions. |
| `capture_request_meta` | `true` | Capture IP address and user agent when an HTTP request is in scope. |
| `retention_days` | `null` | `null` = keep forever. Set to a number for GDPR-style cleanup (daily job deletes entries older than N days). |
| `notes.enabled` | `true` | Enables `POST /auth/admin/users/{id}/notes`. |
| `history.enabled` | `true` | Enables `GET /auth/admin/users/{id}/status/history`. |
| `history.default_per_page` | `20` | Default results per page. |
| `history.max_per_page` | `100` | Maximum allowed per page (enforced server-side). |

---

## 25. `phone` (v2.6)

Optional phone capture at registration plus phone verification via SMS, voice, or WhatsApp. Phone is **not** used for login in this release — it is stored on the user, optionally verified, and feeds the `sms` 2FA method.

```php
'phone' => [
    'enabled'  => env('AUTH_PHONE_ENABLED', false),
    'required' => env('AUTH_PHONE_REQUIRED', false),
    'column'   => env('AUTH_PHONE_COLUMN', 'phone'),
    'verification' => [
        'required_at_registration' => env('AUTH_PHONE_VERIFY_AT_REG', false),
        'default_channel'          => env('AUTH_PHONE_VERIFY_CHANNEL', 'sms'),
        'otp_length'               => env('AUTH_PHONE_OTP_LENGTH', 6),
        'otp_expiry_minutes'       => env('AUTH_PHONE_OTP_EXPIRY', 5),
        'max_attempts'             => env('AUTH_PHONE_OTP_MAX_ATTEMPTS', 5),
    ],
    'providers' => [ /* log | infobip | messagecentral | twilio | firebase | custom */ ],
    'channels'  => [ /* sms | voice | whatsapp → provider + optional fallback */ ],
],
```

| Key | Default | What it does |
|-----|---------|--------------|
| `enabled` | `false` | Master switch. When false the registration form ignores the phone field and the verification endpoints return 404. |
| `required` | `false` | When true, registration fails without a valid phone. When false, phone is optional. |
| `column` | `phone` | Users-table column storing the E.164 phone (added by the v2.6 migration). |
| `verification.required_at_registration` | `false` | When true the user must verify the phone before the account is fully usable; when false phone is saved unverified and verified later via `/auth/phone/send-otp`. |
| `verification.default_channel` | `sms` | Channel used when none is specified: `sms`, `voice`, or `whatsapp`. |
| `verification.otp_length` | `6` | Digits in the phone OTP (4–10). |
| `verification.otp_expiry_minutes` | `5` | Minutes the phone OTP is valid. |
| `verification.max_attempts` | `5` | Wrong-code attempts before the active code is invalidated. |

**Providers and channels.** Each channel picks a provider; each provider maps to a driver class implementing `PhoneDriverContract`. The default `log` driver writes codes to the Laravel log (dev only — the package logs a warning if `log` is active in production). Channels may declare a `fallback` provider used if the primary throws.

```php
'channels' => [
    'sms'      => ['provider' => env('AUTH_PHONE_SMS_PROVIDER', 'log'),      'fallback' => env('AUTH_PHONE_SMS_FALLBACK')],
    'voice'    => ['provider' => env('AUTH_PHONE_VOICE_PROVIDER', 'log'),    'fallback' => env('AUTH_PHONE_VOICE_FALLBACK')],
    'whatsapp' => ['provider' => env('AUTH_PHONE_WHATSAPP_PROVIDER', 'log'), 'fallback' => env('AUTH_PHONE_WHATSAPP_FALLBACK')],
],
```

Custom providers are registered at runtime — see `docs/customization.md` (Custom phone driver). Each built-in provider reads its own credentials from `auth_system.phone.providers.<key>` (e.g. `INFOBIP_API_KEY`, `TWILIO_SID`/`TWILIO_TOKEN`/`TWILIO_FROM`, `MC_CUSTOMER_ID`/`MC_PASSWORD`).

---

## 26. `two_factor` (v2.6)

Two-factor authentication with three equal, parallel-enrollable methods: `totp` (authenticator app), `email` (OTP), `sms` (OTP via the phone driver).

```php
'two_factor' => [
    'enabled'        => env('AUTH_2FA_ENABLED', true),
    'required'       => env('AUTH_2FA_REQUIRED', false),
    'methods'        => ['totp', 'email', 'sms'],
    'default_method' => env('AUTH_2FA_DEFAULT', 'totp'),
    'challenge' => [
        'ttl_seconds'          => env('AUTH_2FA_CHALLENGE_TTL', 300),
        'max_attempts'         => env('AUTH_2FA_CHALLENGE_MAX_ATTEMPTS', 5),
        'burst_max_per_minute' => env('AUTH_2FA_CHALLENGE_BURST', 10),
    ],
    'codes' => [
        'totp'  => ['issuer' => env('AUTH_2FA_TOTP_ISSUER', env('APP_NAME')), 'digits' => 6, 'period' => 30, 'window' => 1],
        'email' => ['length' => 6, 'expiry_minutes' => 10],
        'sms'   => ['length' => 6, 'expiry_minutes' => 5, 'channel' => 'sms'],
    ],
    'backup_codes' => ['enabled' => true, 'count' => 8, 'length' => 10],
    'middleware_behavior' => env('AUTH_2FA_MIDDLEWARE', 'password_confirm'),
    'sudo_ttl_minutes'    => env('AUTH_2FA_SUDO_TTL', 15),
    'rate_limits' => ['challenge' => '5:5', 'enroll' => '5:10'],
],
```

| Key | Default | What it does |
|-----|---------|--------------|
| `enabled` | `true` | Master switch for the 2FA feature and its endpoints. |
| `required` | `false` | When true, every user must enroll in ≥1 method on next login. Combine with the per-user `users.two_factor_required` flag for targeted enforcement. |
| `methods` | all three | Which methods are offered for enrollment. |
| `default_method` | `totp` | Method challenged first at login (user can switch). |
| `challenge.ttl_seconds` | `300` | Challenge lifetime. |
| `challenge.max_attempts` | `5` | Wrong codes before the challenge is invalidated (user must log in again). |
| `challenge.burst_max_per_minute` | `10` | Per-`challenge_token` burst limit — caps brute force on a leaked token regardless of source IP. |
| `codes.totp.window` | `1` | Accepted ± time-steps (RFC 6238). |
| `codes.email.expiry_minutes` | `10` | Email OTP lifetime. |
| `codes.sms.expiry_minutes` / `channel` | `5` / `sms` | SMS OTP lifetime and delivery channel. |
| `backup_codes.{enabled,count,length}` | `true` / `8` / `10` | Single-use recovery codes generated on first enrollment, returned once. |
| `middleware_behavior` | `password_confirm` | Fallback used by `auth.2fa` when the user has no 2FA enrolled: `block`, `force_enroll`, or `password_confirm`. |
| `sudo_ttl_minutes` | `15` | How long a completed 2FA / password-confirm step satisfies `auth.2fa`. |

See `docs/middleware.md` for the `auth.2fa` decision flow and `docs/upgrading.md` (v2.6 section) for the enrollment + challenge endpoints.

---

## 27. `trusted_devices` (v2.6)

A trusted device skips the 2FA challenge at login when its current trust level meets `bypass_2fa_min_level` **and** the client presents the server-issued device token.

```php
'trusted_devices' => [
    'enabled'                        => env('AUTH_TRUSTED_DEVICES_ENABLED', true),
    'level_assignment'               => env('AUTH_TRUST_LEVEL_MODE', 'time'),
    'auto_trust_registration_device' => env('AUTH_TRUST_REG_DEVICE', true),
    'bypass_2fa_min_level'           => env('AUTH_TRUST_BYPASS_MIN', 'high'),
    'token_header'                   => env('AUTH_TRUST_TOKEN_HEADER', 'X-Trusted-Device-Token'),
    'thresholds_days' => ['low' => 15, 'medium' => 60, 'high' => 90],
    'consistency'     => ['max_absence_days' => 30],
    'admin_grant_high' => env('AUTH_TRUST_ADMIN_GRANT_HIGH', false),
],
```

| Key | Default | What it does |
|-----|---------|--------------|
| `enabled` | `true` | Master switch for trusted devices and their endpoints. |
| `level_assignment` | `time` | `time` (pure time-based), `time_consistent` (resets on long absence), or `time_admin` (high requires an admin grant). |
| `auto_trust_registration_device` | `true` | Auto-trust the registration device at `high` so the first login is frictionless. |
| `bypass_2fa_min_level` | `high` | Devices below this level always get a 2FA challenge. Raised from `medium` in v2.6 for safety. |
| `token_header` | `X-Trusted-Device-Token` | Header the client echoes back to prove possession of the one-time device token. **Fingerprint alone never bypasses 2FA** — this token is the real proof. |
| `thresholds_days.{low,medium,high}` | `15` / `60` / `90` | Days of trusted usage required to reach each level. |
| `consistency.max_absence_days` | `30` | Under `time_consistent`, an absence longer than this resets trust progress. |
| `admin_grant_high` | `false` | Under `time_admin`, whether admins may grant `high` via the admin endpoint. |

**Security model.** When a device is trusted, the package issues a one-time random token (returned in the registration response and in `/auth/2fa/challenge` when `trust_device=true`) and stores only its SHA-256. The client must send the plaintext back as `token_header` on future logins. See the v2.6 section of `docs/upgrading.md` for the client integration steps and `docs/middleware.md` for how the bypass interacts with `auth.2fa` step-up.

---

## 28. `messages`

Static per-key overrides for success response messages. Leave as `null` (default) to use the built-in English or the active locale's translation.

**Resolution order:** `config('auth_system.messages.<key>')` → `trans('auth_system::messages.<key>')` → built-in English fallback.

```php
'messages' => [
    'register_initiated'     => null,   // "Verification sent. Please check your email."
    'register_verified'      => null,   // "Email verified. Please set your password."
    'register_complete'      => null,   // "Registration complete."
    'verification_resent'    => null,   // "Verification email resent."
    'login_success'          => null,   // "Login successful."
    'me_retrieved'           => null,   // "User retrieved."
    'logout_success'         => null,   // "Logged out successfully."
    'logout_all_success'     => null,   // "Logged out from all devices."
    'password_reset_sent'    => null,   // "Password reset instructions sent."
    'password_reset_otp_ok'  => null,   // "OTP verified. Submit your new password..."
    'password_reset_link_ok' => null,   // "Link validated. Submit your new password..."
    'password_reset_success' => null,   // "Password reset successfully. You are now logged in."
    'password_changed'       => null,   // "Password changed successfully."
    'sessions_retrieved'     => null,   // "Sessions retrieved."
    'session_terminated'     => null,   // "Session terminated."
    'api_tokens_retrieved'   => null,
    'api_token_created'      => null,
    'api_token_updated'      => null,
    'api_token_revoked'      => null,
    'account_deleted'        => null,
    'account_restored'       => null,
    'account_status_updated' => null,
    'account_deactivated'    => null,
    'account_reactivated'    => null,
],
```

**Branded example:**

```php
'messages' => [
    'register_initiated' => 'Almost there! We sent a verification code to your inbox.',
    'register_complete'  => 'Welcome to Acme. Your account is ready.',
    'login_success'      => 'Welcome back!',
],
```

---

## 29. `errors`

Static per-key overrides for error messages. Same resolution order as `messages`.

Some keys support Laravel-style `:placeholder` replacement:

| Key | Placeholder |
|---|---|
| `account_locked` | `:seconds` (seconds remaining) |
| `social_provider_disabled` | `:provider` |
| `social_authentication_failed` | `:provider` |
| `social_email_unverified` | `:provider` |

```php
'errors' => [
    'invalid_credentials'           => null,
    'account_inactive'              => null,
    'email_not_verified'            => null,
    'otp_invalid'                   => null,
    'otp_expired'                   => null,
    'completion_token_invalid'      => null,
    'registration_session_expired'  => null,
    'email_already_registered'      => null,
    'reset_token_invalid'           => null,
    'current_password_invalid'      => null,
    'refresh_token_invalid'         => null,
    'refresh_token_revoked'         => null,
    'refresh_token_reused'          => null,
    'refresh_token_expired'         => null,
    'api_token_invalid_format'      => null,
    'api_token_invalid_encoding'    => null,
    'api_token_revoked'             => null,
    'api_token_expired'             => null,
    'social_provider_disabled'      => null,
    'social_authentication_failed'  => null,
    'social_email_unverified'       => null,
    'social_link_token_invalid'     => null,
    'social_user_not_found'         => null,
    'session_not_found'             => null,
    'account_locked'                => null,
    'unauthenticated'               => null,
    // v2.4
    'account_disabled'              => null,
    'account_suspended'             => null,
    'account_deletion_disabled'     => null,
    'account_deactivation_disabled' => null,
    'account_status_invalid'        => null,
    'account_password_mismatch'     => null,
],
```

---

## 30. Complete `.env` Reference

```env
# ── Auth mode ─────────────────────────────────────────────────────────────────
AUTH_MODE=both                          # api | web | both
AUTH_SPA_TOKEN=false                    # true = SPA clients get Bearer token in 'both' mode
AUTH_REQUIRE_VERIFICATION=true          # false = allow login without email verification

# ── Routes ────────────────────────────────────────────────────────────────────
AUTH_ROUTES_REGISTER=true               # false = don't auto-mount; include manually
AUTH_ROUTES_PREFIX=auth                 # URL prefix (e.g. api/v1/auth)

# ── Verification ──────────────────────────────────────────────────────────────
AUTH_VERIFICATION_METHOD=both           # otp | magic_link | both
AUTH_OTP_LENGTH=6                       # digits in OTP code (4–8)
AUTH_OTP_EXPIRY=10                      # minutes until OTP expires
AUTH_OTP_MAX_ATTEMPTS=5                 # wrong guesses before OTP is invalidated
AUTH_MAGIC_EXPIRY=30                    # minutes until magic link expires
AUTH_MAGIC_LINK_TARGET=backend          # backend | frontend
AUTH_FRONTEND_VERIFY_URL=               # required when magic_link_target=frontend
AUTH_FRONTEND_RESET_URL=                # required when magic_link_target=frontend
AUTH_PENDING_TTL=60                     # minutes to keep pending registration in cache

# ── Password reset ────────────────────────────────────────────────────────────
AUTH_PASSWORD_RESET_METHOD=             # null | otp | magic_link | both

# ── Password policy ───────────────────────────────────────────────────────────
AUTH_PASSWORD_MIN=8
AUTH_PASSWORD_UPPERCASE=false
AUTH_PASSWORD_NUMBER=false
AUTH_PASSWORD_SPECIAL=false

# ── Token TTL (minutes) ───────────────────────────────────────────────────────
AUTH_TOKEN_TTL_MOBILE=10080             # 7 days
AUTH_REFRESH_TTL_MOBILE=43200           # 30 days
AUTH_TOKEN_TTL_SPA=1440                 # 24 hours
AUTH_REFRESH_TTL_SPA=10080              # 7 days
AUTH_TOKEN_TTL_API=525600               # 365 days
AUTH_REFRESH_TTL_API=0                  # 0 = never expires
AUTH_SESSION_TTL=120                    # keep in sync with SESSION_LIFETIME

# ── Rate limits ("max:decay_minutes") ─────────────────────────────────────────
AUTH_RATE_REGISTER=5:1
AUTH_RATE_LOGIN=5:1
AUTH_RATE_OTP_SEND=3:1
AUTH_RATE_OTP_VERIFY=10:5
AUTH_RATE_PASSWORD_RESET=3:1

# ── Roles ─────────────────────────────────────────────────────────────────────
AUTH_DEFAULT_ROLE=user

# ── OTP channel ───────────────────────────────────────────────────────────────
AUTH_OTP_CHANNEL=email                  # email | FQCN of OtpChannelContract impl

# ── Google OAuth ──────────────────────────────────────────────────────────────
AUTH_GOOGLE_ENABLED=false
AUTH_GOOGLE_CLIENT_ID=
AUTH_GOOGLE_CLIENT_SECRET=
AUTH_GOOGLE_REDIRECT=
AUTH_SOCIAL_FRONTEND_URL=               # optional — redirect after social link confirm
AUTH_SOCIAL_PROFILE_COMPLETION=false   # v2.6 — require custom fields after OAuth signup
AUTH_SOCIAL_PROFILE_COMPLETION_TTL=15  # minutes the completion token is valid

# ── Reverb ────────────────────────────────────────────────────────────────────
AUTH_REVERB_ENABLED=false

# ── API tokens ────────────────────────────────────────────────────────────────
AUTH_API_TOKENS_ENABLED=false

# ── Queue ─────────────────────────────────────────────────────────────────────
AUTH_QUEUE_CONNECTION=                  # null = app default
AUTH_QUEUE_NAME=auth-maintenance

# ── Response format ───────────────────────────────────────────────────────────
AUTH_RESPONSE_FORMATTER=               # FQCN or empty

# ── Security ──────────────────────────────────────────────────────────────────
AUTH_NOTIFY_NEW_DEVICE=true
AUTH_LOCKOUT_ENABLED=true
AUTH_LOCKOUT_MAX=10
AUTH_LOCKOUT_DECAY=15

# ── Account status (v2.4) ──────────────────────────────────────────────────────
AUTH_ACCOUNT_STATUS_ENABLED=true
AUTH_ACCOUNT_STATUS_COLUMN=account_status
AUTH_ACCOUNT_STATUS_DEFAULT=active
AUTH_ACCOUNT_STATUS_REVOKE_ON_CHANGE=true
AUTH_ACCOUNT_STATUS_ABILITY=super-admin|admin

# ── Timed bans (v2.4) ──────────────────────────────────────────────────────────
AUTH_ACCOUNT_AUTO_UNBAN=true
AUTH_ACCOUNT_AUTO_UNBAN_SWEEP=5         # sweep every N minutes

# ── Account deletion (v2.4) ────────────────────────────────────────────────────
AUTH_ACCOUNT_DELETE_ENABLED=true
AUTH_ACCOUNT_DELETE_SELF=true
AUTH_ACCOUNT_DELETE_REQUIRE_PASSWORD=true
AUTH_ACCOUNT_DELETE_GRACE_DAYS=30
AUTH_ACCOUNT_AUTO_RESTORE=true
AUTH_ACCOUNT_NULL_UNIQUES=true
AUTH_ACCOUNT_HARD_DELETE=false
AUTH_ACCOUNT_AUDIT_TABLE=true
AUTH_ACCOUNT_UNIQUE_COLUMNS=auto

# ── Account deactivation (v2.4) ────────────────────────────────────────────────
AUTH_ACCOUNT_DEACTIVATE_ENABLED=true
AUTH_ACCOUNT_DEACTIVATE_SELF=true
AUTH_ACCOUNT_DEACTIVATE_REQUIRE_PASSWORD=true
AUTH_ACCOUNT_AUTO_REACTIVATE=true

# ── Audit log (v2.4) ───────────────────────────────────────────────────────────
AUTH_ACCOUNT_AUDIT_ENABLED=true
AUTH_ACCOUNT_AUDIT_TABLE_NAME=account_status_logs
AUTH_ACCOUNT_AUDIT_LOG_STATUS=true
AUTH_ACCOUNT_AUDIT_LOG_SYSTEM=true
AUTH_ACCOUNT_AUDIT_CAPTURE_META=true
AUTH_ACCOUNT_AUDIT_RETENTION_DAYS=      # null = keep forever
AUTH_ACCOUNT_AUDIT_NOTES_ENABLED=true
AUTH_ACCOUNT_AUDIT_HISTORY_ENABLED=true
AUTH_ACCOUNT_AUDIT_HISTORY_PER_PAGE=20
AUTH_ACCOUNT_AUDIT_HISTORY_MAX_PER_PAGE=100

# ── Referral codes (v2.3) ─────────────────────────────────────────────────────
AUTH_REFERRAL_CODE_ENABLED=false
AUTH_REFERRAL_CODE_COLUMN=referral_code
AUTH_REFERRAL_CODE_LENGTH=10
AUTH_REFERRAL_CODE_UPPERCASE=true
AUTH_REFERRAL_CODE_GENERATOR=           # FQCN or empty

# ── Phone (v2.6) ──────────────────────────────────────────────────────────────
AUTH_PHONE_ENABLED=false
AUTH_PHONE_REQUIRED=false               # true = registration fails without a phone
AUTH_PHONE_COLUMN=phone
AUTH_PHONE_VERIFY_AT_REG=false          # true = must verify phone before account is usable
AUTH_PHONE_VERIFY_CHANNEL=sms           # sms | voice | whatsapp
AUTH_PHONE_OTP_LENGTH=6
AUTH_PHONE_OTP_EXPIRY=5                  # minutes
AUTH_PHONE_OTP_MAX_ATTEMPTS=5
AUTH_PHONE_SMS_PROVIDER=                # REQUIRED when phone enabled — infobip | messagecentral | twilio | firebase | custom | log (log = local dev only)
AUTH_PHONE_SMS_FALLBACK=                # optional provider used if primary fails
AUTH_PHONE_VOICE_PROVIDER=
AUTH_PHONE_VOICE_FALLBACK=
AUTH_PHONE_WHATSAPP_PROVIDER=
AUTH_PHONE_WHATSAPP_FALLBACK=
# Note: the `log` driver writes plaintext OTP codes to the Laravel log and
# REFUSES to run outside local/testing. There is no default provider — set one
# explicitly. Use `log` only for local development.
# Provider credentials (only set the ones you use):
INFOBIP_API_KEY=
INFOBIP_BASE_URL=https://api.infobip.com
INFOBIP_SENDER=
MC_CUSTOMER_ID=
MC_PASSWORD=
MC_BASE_URL=https://cpaas.messagecentral.com
TWILIO_SID=
TWILIO_TOKEN=
TWILIO_FROM=
FIREBASE_PROJECT_ID=
FIREBASE_CREDENTIALS=

# ── Two-factor authentication (v2.6) ──────────────────────────────────────────
AUTH_2FA_ENABLED=true
AUTH_2FA_REQUIRED=false                 # true = force all users to enroll
AUTH_2FA_DEFAULT=totp                   # totp | email | sms
AUTH_2FA_CHALLENGE_TTL=300              # seconds
AUTH_2FA_CHALLENGE_MAX_ATTEMPTS=5       # wrong codes before challenge invalidated
AUTH_2FA_CHALLENGE_BURST=10            # per-challenge_token burst cap per minute
AUTH_2FA_TOTP_ISSUER=                   # defaults to APP_NAME
AUTH_2FA_BACKUP_ENABLED=true
AUTH_2FA_BACKUP_COUNT=8
AUTH_2FA_BACKUP_LENGTH=10
AUTH_2FA_MIDDLEWARE=password_confirm    # block | force_enroll | password_confirm
AUTH_2FA_SUDO_TTL=15                    # minutes a completed step satisfies auth.2fa
AUTH_2FA_RATE_CHALLENGE=5:5
AUTH_2FA_RATE_ENROLL=5:10

# ── Trusted devices (v2.6) ────────────────────────────────────────────────────
AUTH_TRUSTED_DEVICES_ENABLED=true
AUTH_TRUST_LEVEL_MODE=time              # time | time_consistent | time_admin
AUTH_TRUST_REG_DEVICE=true              # auto-trust the registration device at 'high'
AUTH_TRUST_BYPASS_MIN=high              # low | medium | high — min level to skip 2FA
AUTH_TRUST_TOKEN_HEADER=X-Trusted-Device-Token
AUTH_TRUST_LOW_DAYS=15
AUTH_TRUST_MEDIUM_DAYS=60
AUTH_TRUST_HIGH_DAYS=90
AUTH_TRUST_MAX_ABSENCE=30               # days (time_consistent mode)
AUTH_TRUST_ADMIN_GRANT_HIGH=false       # time_admin mode
```
