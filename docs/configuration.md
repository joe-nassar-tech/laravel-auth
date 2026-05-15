# Configuration Reference

Full reference for every key in `config/auth_system.php`. All keys can be overridden via `.env`.

> Looking for response-message overrides or multi-language support? See **[docs/customization.md](customization.md)** (per-key overrides, referral codes, transformers) and **[docs/localization.md](localization.md)** (translation files, locale switching, RTL).

---

## `mode`

**Env**: `AUTH_MODE` · **Default**: `both`

Controls how credentials are issued after a successful login.

| Value | Behaviour |
|---|---|
| `web` | Session cookie only. No tokens issued. Best for traditional web apps. |
| `api` | Bearer token always. Best for pure API / mobile backends. |
| `both` | Runtime detection (default). Mobile header → token; otherwise → cookie. |

**Detection order in `both` mode:**
1. `X-Client-Type: mobile` header → Bearer token (mobile TTL)
2. `AUTH_SPA_TOKEN=true` and no `X-Client-Type` → Bearer token (SPA TTL)
3. Otherwise → session cookie

```env
AUTH_MODE=both
```

---

## `spa_token`

**Env**: `AUTH_SPA_TOKEN` · **Default**: `false`

Only applies in `both` mode. When `true`, browser (SPA) clients receive a Bearer token instead of a session cookie.

```env
AUTH_SPA_TOKEN=false   # cookie-based SPA (recommended)
AUTH_SPA_TOKEN=true    # token-based SPA
```

---

## `require_email_verification`

**Env**: `AUTH_REQUIRE_VERIFICATION` · **Default**: `true`

When `true`, login is blocked with HTTP 403 until the user verifies their email.

```env
AUTH_REQUIRE_VERIFICATION=true    # public apps (default)
AUTH_REQUIRE_VERIFICATION=false   # internal tools / dev environments
```

---

## `registration`

Controls extra fields accepted during registration.

### `registration.extra_fields_rules`

**Default**: `[]`

A map of field name → Laravel validation rule string. These fields are validated and passed to `User::create()`.

```php
'extra_fields_rules' => [
    'phone'   => 'required|string|max:20',
    'country' => 'required|string|size:2',
    'dob'     => 'nullable|date|before:today',
],
```

Make sure each field name is in your `User` model's `$fillable` array.

### `registration.request_class`

**Default**: `null`

Point to a custom `FormRequest` class (must extend `RegisterRequest`) for full validation control. Takes priority over `extra_fields_rules` when set.

```php
'request_class' => \App\Http\Requests\MyRegisterRequest::class,
```

---

## `verification`

Controls how OTP codes and magic links are sent during registration.

### `verification.method`

**Env**: `AUTH_VERIFICATION_METHOD` · **Default**: `both`

| Value | Behaviour |
|---|---|
| `otp` | Send a numeric code only (e.g. `482910`) |
| `magic_link` | Send a clickable link only |
| `both` | Send one email containing both the OTP and the magic link |

```env
AUTH_VERIFICATION_METHOD=both
```

### `verification.otp_length`

**Env**: `AUTH_OTP_LENGTH` · **Default**: `6`

Number of digits in the OTP code. Clamped to the range 4–8.

```env
AUTH_OTP_LENGTH=6   # → "482910"
AUTH_OTP_LENGTH=8   # → "48291073"
```

### `verification.otp_expiry`

**Env**: `AUTH_OTP_EXPIRY` · **Default**: `10`

Minutes until the OTP code expires.

```env
AUTH_OTP_EXPIRY=10
```

### `verification.otp_max_attempts`

**Env**: `AUTH_OTP_MAX_ATTEMPTS` · **Default**: `5`

Number of incorrect OTP submissions allowed before the active OTP is invalidated. Defends against brute-forcing the 6-digit space.

```env
AUTH_OTP_MAX_ATTEMPTS=5
```

### `verification.magic_expiry`

**Env**: `AUTH_MAGIC_EXPIRY` · **Default**: `30`

Minutes until the magic link expires.

```env
AUTH_MAGIC_EXPIRY=30
```

### `verification.magic_link_target`

**Env**: `AUTH_MAGIC_LINK_TARGET` · **Default**: `backend`

| Value | Behaviour |
|---|---|
| `backend` | Magic link points to `GET /auth/register/verify-magic/{token}` |
| `frontend` | Magic link points to `AUTH_FRONTEND_VERIFY_URL?token={uuid}`. Your frontend must call the backend verification endpoint itself. |

```env
AUTH_MAGIC_LINK_TARGET=backend
```

### `verification.frontend_verify_url`

**Env**: `AUTH_FRONTEND_VERIFY_URL` · **Default**: `null`

Required when `AUTH_MAGIC_LINK_TARGET=frontend`. The library appends `?token=xxx` to this URL.

```env
AUTH_FRONTEND_VERIFY_URL=https://yourapp.com/verify-email
```

### `verification.frontend_reset_url`

**Env**: `AUTH_FRONTEND_RESET_URL` · **Default**: `null`

Required when `AUTH_MAGIC_LINK_TARGET=frontend` and you use magic-link password resets.

```env
AUTH_FRONTEND_RESET_URL=https://yourapp.com/reset-password
```

---

## `password_reset`

### `password_reset.method`

**Env**: `AUTH_PASSWORD_RESET_METHOD` · **Default**: `null`

Controls how password reset codes/links are delivered, independently from registration verification. When `null`, inherits `verification.method`.

```env
# Inherit from AUTH_VERIFICATION_METHOD (default)
AUTH_PASSWORD_RESET_METHOD=

# Always use OTP for password resets, regardless of verification method
AUTH_PASSWORD_RESET_METHOD=otp
```

---

## `token_ttl`

Controls token lifetime per client type. Set any value to `0` for a token that never expires (not recommended for access tokens).

### Mobile (`X-Client-Type: mobile`)

```env
AUTH_TOKEN_TTL_MOBILE=10080     # 7 days (minutes)
AUTH_REFRESH_TTL_MOBILE=43200   # 30 days
```

### SPA (browser, when AUTH_SPA_TOKEN=true)

```env
AUTH_TOKEN_TTL_SPA=1440         # 24 hours
AUTH_REFRESH_TTL_SPA=10080      # 7 days
```

### API (AUTH_MODE=api)

```env
AUTH_TOKEN_TTL_API=525600       # 365 days
AUTH_REFRESH_TTL_API=0          # never expires
```

---

## `rate_limits`

Format: `"max_attempts:decay_minutes"`

Rate limits apply per IP address AND per email independently. Exceeding either triggers HTTP 429.

| Key | Env | Default | Endpoint |
|---|---|---|---|
| `register` | `AUTH_RATE_REGISTER` | `5:1` | `POST /auth/register` |
| `login` | `AUTH_RATE_LOGIN` | `5:1` | `POST /auth/login`, `POST /auth/token/refresh` |
| `otp_verify` | `AUTH_RATE_OTP_VERIFY` | `10:5` | OTP verification endpoints |
| `otp_send` | `AUTH_RATE_OTP_SEND` | `3:1` | `POST /auth/email/resend-verification` |
| `password_reset` | `AUTH_RATE_PASSWORD_RESET` | `3:1` | `POST /auth/password/forgot` |

**Stricter production settings:**

```env
AUTH_RATE_LOGIN=3:5
AUTH_RATE_PASSWORD_RESET=2:10
AUTH_RATE_OTP_VERIFY=5:5
```

---

## `password`

Password policy enforced at registration and when changing/resetting a password.

| Key | Env | Default | Meaning |
|---|---|---|---|
| `min_length` | `AUTH_PASSWORD_MIN` | `8` | Minimum character count |
| `require_uppercase` | `AUTH_PASSWORD_UPPERCASE` | `false` | Must contain A–Z |
| `require_number` | `AUTH_PASSWORD_NUMBER` | `false` | Must contain 0–9 |
| `require_special` | `AUTH_PASSWORD_SPECIAL` | `false` | Must contain a symbol |
| `pending_ttl_minutes` | `AUTH_PENDING_TTL` | `60` | Minutes to keep a pending registration in cache |

**Strict policy example:**

```env
AUTH_PASSWORD_MIN=12
AUTH_PASSWORD_UPPERCASE=true
AUTH_PASSWORD_NUMBER=true
AUTH_PASSWORD_SPECIAL=true
```

---

## `roles`

Spatie Permission integration.

### `roles.default_role`

**Env**: `AUTH_DEFAULT_ROLE` · **Default**: `user`

Role auto-assigned on registration. Must exist in your database — run `AuthRolesSeeder` first.

```env
AUTH_DEFAULT_ROLE=user
```

### `roles.seeded_roles`

**Default**: `['super-admin', 'admin', 'user']`

Roles that `AuthRolesSeeder` creates. Not an env variable — edit `config/auth_system.php` directly or extend the seeder.

---

## `otp_channel`

### `otp_channel.driver`

**Env**: `AUTH_OTP_CHANNEL` · **Default**: `email`

How OTP codes and magic links are delivered. The built-in `email` driver sends Blade-template emails.

To switch channels, point to any class implementing `OtpChannelContract`:

```env
AUTH_OTP_CHANNEL=email
AUTH_OTP_CHANNEL=App\Channels\SmsOtpChannel
```

---

## `mail`

Override individual email notifications. When `null`, the built-in Blade template is used.

| Key | Email |
|---|---|
| `otp_verify_notification` | OTP code — registration |
| `otp_reset_notification` | OTP code — password reset |
| `magic_link_verify_notification` | Magic link — registration |
| `magic_link_reset_notification` | Magic link — password reset |
| `otp_verify_combined_notification` | OTP + magic link — registration (method=both) |
| `otp_reset_combined_notification` | OTP + magic link — password reset (method=both) |

Constructor signature: `($code, $type, $context)`. Combined: `($code, $url, $type, $context)`.

```php
'mail' => [
    'otp_reset_notification' => \App\Notifications\CustomResetEmail::class,
],
```

---

## `social`

### `social.google`

| Key | Env | Description |
|---|---|---|
| `enabled` | `AUTH_GOOGLE_ENABLED` | Enable Google OAuth (default: `false`) |
| `client_id` | `AUTH_GOOGLE_CLIENT_ID` | OAuth 2.0 Client ID from Google Cloud Console |
| `client_secret` | `AUTH_GOOGLE_CLIENT_SECRET` | OAuth 2.0 Client Secret |
| `redirect` | `AUTH_GOOGLE_REDIRECT` | Authorized redirect URI (must match Google Console) |

```env
AUTH_GOOGLE_ENABLED=true
AUTH_GOOGLE_CLIENT_ID=123456789.apps.googleusercontent.com
AUTH_GOOGLE_CLIENT_SECRET=GOCSPX-xxxx
AUTH_GOOGLE_REDIRECT=https://yourapp.com/auth/social/google/callback
```

### `social.frontend_url`

**Env**: `AUTH_SOCIAL_FRONTEND_URL` · **Default**: `null`

After a social account-link confirmation, the browser is redirected to this URL with `?linked=true&provider=google` appended. When `null`, the library returns JSON.

```env
AUTH_SOCIAL_FRONTEND_URL=https://yourapp.com/auth/callback
```

---

## `reverb`

### `reverb.enabled`

**Env**: `AUTH_REVERB_ENABLED` · **Default**: `false`

When `true`, the library broadcasts an `EmailVerified` event over WebSocket after email verification. Requires `laravel/reverb` to be installed and configured.

**Frontend usage:**

```js
// Subscribe before the user verifies
Echo.private(`auth.verification.${tempToken}`)
    .listen('EmailVerified', (e) => {
        // e.verified === true
        // Call POST /auth/register/complete with your stored completion_token
    });
```

```env
AUTH_REVERB_ENABLED=true
```

---

## `require_email_verification`

Already covered above.

---

## `api_tokens`

### `api_tokens.enabled`

**Env**: `AUTH_API_TOKENS_ENABLED` · **Default**: `false`

Enables the long-lived API token system and its routes. Leave disabled unless your app needs third-party integrations.

### `api_tokens.abilities_default`

**Default**: `['read']`

Default abilities assigned when a token is created without specifying `abilities`.

```env
AUTH_API_TOKENS_ENABLED=true
```

---

## `queue`

Maintenance jobs run on this queue:

| Job | Frequency | Active when |
|---|---|---|
| `CleanExpiredOtpRecords` | Every 5 minutes | Always |
| `CleanExpiredRefreshTokens` | Hourly | Always |
| `CleanExpiredApiTokens` | Hourly | `api_tokens.enabled=true` |

```env
AUTH_QUEUE_CONNECTION=redis        # null = app default
AUTH_QUEUE_NAME=auth-maintenance
```

Run the queue worker:

```bash
php artisan queue:work --queue=auth-maintenance
```

---

## `response`

### `response.formatter`

**Env**: `AUTH_RESPONSE_FORMATTER` · **Default**: `null`

FQCN of a class implementing `ResponseFormatterContract`. When `null`, uses the default `{ success, message, data, errors }` envelope.

```env
AUTH_RESPONSE_FORMATTER=App\Auth\MyResponseFormatter
```

---

## `security`

### `security.notify_new_device_login`

**Env**: `AUTH_NOTIFY_NEW_DEVICE` · **Default**: `true`

Sends an email alert when a user logs in from a browser/OS combination not seen before.

```env
AUTH_NOTIFY_NEW_DEVICE=true
```

### `security.lockout.enabled`

**Env**: `AUTH_LOCKOUT_ENABLED` · **Default**: `true`

Enables per-account lockout after repeated failed logins.

### `security.lockout.max_attempts`

**Env**: `AUTH_LOCKOUT_MAX` · **Default**: `10`

Failed login attempts before the account is locked.

### `security.lockout.decay_minutes`

**Env**: `AUTH_LOCKOUT_DECAY` · **Default**: `15`

Minutes the lockout lasts. After this, the user can try again automatically.

**Recommended production settings:**

```env
AUTH_NOTIFY_NEW_DEVICE=true
AUTH_LOCKOUT_ENABLED=true
AUTH_LOCKOUT_MAX=5
AUTH_LOCKOUT_DECAY=30
```
