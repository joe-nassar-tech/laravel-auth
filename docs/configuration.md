# Configuration Reference

This document covers **every key** in `config/auth_system.php` — what it does,
what values it accepts, which `.env` variable controls it, and exactly how to
override it. Every override mechanic (contracts, custom classes, transformers,
language files) is shown with working code you can copy directly into your app.

> **Quick orientation:** publish the config file first so you can edit it in
> your own project:
>
> ```bash
> php artisan vendor:publish --tag=auth-config
> ```
>
> This copies the file to `config/auth_system.php`. Change values there or via
> `.env` — both work. `.env` takes priority for every key that reads `env()`.

---

## Table of Contents

1. [mode](#mode)
2. [spa_token](#spa_token)
3. [require_email_verification](#require_email_verification)
4. [routes](#routes)
5. [registration](#registration)
   - [extra_fields_rules](#extra_fields_rules)
   - [extra_fields_messages](#extra_fields_messages)
   - [extra_fields_transformers](#extra_fields_transformers)
   - [request_class](#request_class)
6. [referral_code](#referral_code)
7. [verification](#verification)
8. [password_reset](#password_reset)
9. [token_ttl](#token_ttl)
10. [rate_limits](#rate_limits)
11. [password](#password)
12. [roles](#roles)
13. [otp_channel](#otp_channel)
14. [mail](#mail)
15. [social](#social)
16. [reverb](#reverb)
17. [api_tokens](#api_tokens)
18. [queue](#queue)
19. [response](#response)
20. [security](#security)
21. [account](#account)
    - [account.status](#accountstatus)
    - [account.deletion](#accountdeletion)
    - [account.deactivation](#accountdeactivation)
    - [account.audit](#accountaudit)
22. [errors and messages](#errors-and-messages)
23. [Contracts — writing your own overrides](#contracts--writing-your-own-overrides)
24. [Events — hooking into the lifecycle](#events--hooking-into-the-lifecycle)
25. [Complete .env reference](#complete-env-reference)

---

## `mode`

**Env:** `AUTH_MODE` | **Default:** `both`

Controls what credential type the server returns after a successful login.

| Value | What happens |
|---|---|
| `api` | Always returns a Bearer token. Use for pure API or mobile backends. |
| `web` | Always uses a Laravel session cookie. Use for traditional server-rendered apps. |
| `both` | Auto-detects at runtime based on the request (see below). Use when you serve both a mobile app and a browser SPA from the same backend. |

**How detection works in `both` mode** (checked in this order):

1. Request has `X-Client-Type: mobile` header → Bearer token (mobile TTL applies).
2. `AUTH_SPA_TOKEN=true` and no `X-Client-Type` header → Bearer token (SPA TTL applies).
3. Everything else → session cookie (no token).

```env
AUTH_MODE=both
```

---

## `spa_token`

**Env:** `AUTH_SPA_TOKEN` | **Default:** `false`

Only relevant when `AUTH_MODE=both`.

- `false` → Browser requests (SPA) get a session cookie. This is the default and is the most secure choice for browser clients.
- `true` → Browser requests get a Bearer token instead, just like mobile.

```env
AUTH_SPA_TOKEN=false
```

---

## `require_email_verification`

**Env:** `AUTH_REQUIRE_VERIFICATION` | **Default:** `true`

- `true` → A user who has not verified their email cannot log in. Login returns HTTP 403.
- `false` → Users can log in immediately after registering, without verifying their email. Useful for internal tools or development environments.

```env
AUTH_REQUIRE_VERIFICATION=true
```

---

## `routes`

Controls how and where the package mounts its HTTP routes.

### `routes.register`

**Env:** `AUTH_ROUTES_REGISTER` | **Default:** `true`

- `true` → The package registers its routes automatically at boot. This is the default — you do not need to do anything.
- `false` → The package does NOT register routes automatically. You are responsible for including the route file manually. Use this when you need full control over URL structure or middleware ordering.

**Manual mount example** (use when `register = false`):

```php
// routes/api.php
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/auth')
    ->middleware(['api', 'throttle:api'])
    ->group(base_path('vendor/joe-404/laravel-auth/routes/auth.php'));
```

```env
AUTH_ROUTES_REGISTER=true
```

### `routes.prefix`

**Env:** `AUTH_ROUTES_PREFIX` | **Default:** `auth`

The URL prefix for all package routes. With the default value `auth`, routes are available at `/auth/login`, `/auth/register`, etc. If you want versioned URLs, change this to `api/v1/auth` and they become `/api/v1/auth/login`, etc.

```env
AUTH_ROUTES_PREFIX=auth           # → /auth/login
AUTH_ROUTES_PREFIX=api/v1/auth    # → /api/v1/auth/login
```

### `routes.middleware`

**Default:** `null` (the package picks the right middleware automatically based on `mode`)

When `null`, the package applies:
- `api` mode → `['api']`
- `web` / `both` mode → session + cookie + CSRF + `api` (appropriate for browser clients)

Set to an array to completely override the middleware stack:

```php
// config/auth_system.php
'routes' => [
    'middleware' => ['api', 'my-custom-throttle', 'my-logging'],
],
```

---

## `registration`

Options that extend what data users can submit during registration.

### `extra_fields_rules`

**Default:** `[]`

A map of `field_name => validation_rules`. These fields are validated alongside the built-in `email` field and are written straight into `User::create()` when registration is finalized (step 3 of the 3-step flow).

**Rules can be a string** (pipe-separated, standard Laravel format):

```php
'extra_fields_rules' => [
    'phone'   => 'nullable|string|max:20',
    'country' => 'required|string|size:2',
],
```

**Or an array** (when you need objects like Rule::unique() or custom rule classes):

```php
'extra_fields_rules' => [
    'username'      => ['required', 'string', 'min:3', 'max:30', 'unique:users,username'],
    'date_of_birth' => ['required', 'date', new \App\Rules\Age18Plus()],
    'agreed_terms'  => ['required', 'accepted'],
],
```

**Important:** every field name listed here must be in your `User` model's `$fillable` array, otherwise it will be silently ignored by `User::create()`.

```php
// app/Models/User.php
protected $fillable = [
    'name', 'email', 'password',
    'phone', 'country', 'username', 'date_of_birth',
    // ... whatever you add here
];
```

### `extra_fields_messages`

**Default:** `[]`

Custom validation error messages for extra fields. Uses the standard Laravel `field.rule` key format. Lets you give users friendly, branded messages instead of Laravel's generic defaults.

```php
'extra_fields_messages' => [
    'username.required' => 'Please choose a username.',
    'username.unique'   => 'That username is already taken. Try another.',
    'username.min'      => 'Username must be at least 3 characters.',
    'username.regex'    => 'Username can only contain letters, numbers, and underscores.',
    'phone.max'         => 'Phone number is too long.',
    'agreed_terms.accepted' => 'You must accept our Terms of Service to continue.',
    'date_of_birth.required' => 'Please enter your date of birth.',
],
```

Any key not listed here falls back to Laravel's built-in message.

### `extra_fields_transformers`

**Default:** `[]`

Transformers let you **derive or normalize a field** from the validated registration input before it is written to the database — without writing a custom controller or request class.

A common use case: you ask users for a `username` but want to also store a `username_normalized` (all lowercase) for case-insensitive lookups.

**Step 1 — Create a transformer class** that implements `ExtraFieldTransformerContract`:

```php
// app/Transformers/UsernameNormalizer.php
<?php

namespace App\Transformers;

use Joe404\LaravelAuth\Contracts\ExtraFieldTransformerContract;

class UsernameNormalizer implements ExtraFieldTransformerContract
{
    public function transform(array $validated): mixed
    {
        // $validated contains all validated fields (email + all extra fields).
        // Return the value you want stored in the target column.
        return strtolower(trim($validated['username']));
    }
}
```

**Step 2 — Register the transformer** in config. The key is the column name where the result is stored; the value is the class name:

```php
'extra_fields_transformers' => [
    'username_normalized' => \App\Transformers\UsernameNormalizer::class,
],
```

**Step 3 — Add the column** to your migration and your `User` model's `$fillable`:

```php
// In your migration
$table->string('username_normalized')->nullable()->unique();

// In User.php
protected $fillable = [..., 'username_normalized'];
```

The package calls `transform($validated)` on each transformer after validation passes and before `User::create()`. The returned value is merged into the data array under the transformer's key.

**Another example — derive a `display_name` from `first_name` + `last_name`:**

```php
class DisplayNameTransformer implements ExtraFieldTransformerContract
{
    public function transform(array $validated): mixed
    {
        return trim($validated['first_name'] . ' ' . $validated['last_name']);
    }
}

// config
'extra_fields_transformers' => [
    'display_name' => \App\Transformers\DisplayNameTransformer::class,
],
```

### `request_class`

**Default:** `null`

Points to a custom `FormRequest` class that extends `RegisterRequest`. Use this when `extra_fields_rules` is not enough — for example when you need:
- Complex conditional validation (`required_if`, `sometimes`)
- Cross-field validation (`->after()` callbacks)
- Dependency injection into the request class
- Custom `prepareForValidation()` or `passedValidation()` hooks

**This takes full priority over `extra_fields_rules`, `extra_fields_messages`, and `extra_fields_transformers` when set.**

```php
// app/Http/Requests/FanRegisterRequest.php
<?php

namespace App\Http\Requests;

use Joe404\LaravelAuth\Http\Requests\RegisterRequest;

class FanRegisterRequest extends RegisterRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'username'      => ['required', 'string', 'min:3', 'unique:users'],
            'date_of_birth' => ['required', 'date'],
        ]);
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'username.unique' => 'That username is already taken.',
        ]);
    }
}
```

```php
// config/auth_system.php
'registration' => [
    'request_class' => \App\Http\Requests\FanRegisterRequest::class,
],
```

---

## `referral_code`

When enabled, the package auto-generates a unique referral code for every new user during registration finalization and writes it to the configured column.

| Key | Env | Default | Meaning |
|---|---|---|---|
| `enabled` | `AUTH_REFERRAL_CODE_ENABLED` | `false` | Master switch |
| `column` | `AUTH_REFERRAL_CODE_COLUMN` | `referral_code` | Column on the users table |
| `length` | `AUTH_REFERRAL_CODE_LENGTH` | `10` | Code length in characters |
| `uppercase` | `AUTH_REFERRAL_CODE_UPPERCASE` | `true` | `true` → `"AB12CD34EF"`, `false` → `"ab12cd34ef"` |
| `generator` | `AUTH_REFERRAL_CODE_GENERATOR` | `null` | FQCN of a custom generator class |

**To enable with defaults:**

```env
AUTH_REFERRAL_CODE_ENABLED=true
```

Make sure the column exists and is in `$fillable`:

```php
// Migration
$table->string('referral_code')->nullable()->unique();

// User.php
protected $fillable = [..., 'referral_code'];
```

**Custom generator** — implement `ReferralCodeGeneratorContract` to produce codes in your own format (prefixed, sequential, etc.):

```php
// app/Auth/VanityCodeGenerator.php
<?php

namespace App\Auth;

use Joe404\LaravelAuth\Contracts\ReferralCodeGeneratorContract;

class VanityCodeGenerator implements ReferralCodeGeneratorContract
{
    public function generate(): string
    {
        // Must be unique. Check your DB here if needed.
        return 'CP-' . strtoupper(substr(md5(uniqid()), 0, 8));
        // Example output: "CP-A3F82B91"
    }
}
```

```env
AUTH_REFERRAL_CODE_GENERATOR=App\Auth\VanityCodeGenerator
```

---

## `verification`

Controls how email verification works during registration.

| Key | Env | Default | Meaning |
|---|---|---|---|
| `method` | `AUTH_VERIFICATION_METHOD` | `both` | `otp`, `magic_link`, or `both` |
| `otp_length` | `AUTH_OTP_LENGTH` | `6` | Digits in the OTP code (4–8) |
| `otp_expiry` | `AUTH_OTP_EXPIRY` | `10` | Minutes until OTP expires |
| `otp_max_attempts` | `AUTH_OTP_MAX_ATTEMPTS` | `5` | Wrong OTP guesses before code is invalidated |
| `magic_expiry` | `AUTH_MAGIC_EXPIRY` | `30` | Minutes until magic link expires |
| `magic_link_target` | `AUTH_MAGIC_LINK_TARGET` | `backend` | `backend` or `frontend` |
| `frontend_verify_url` | `AUTH_FRONTEND_VERIFY_URL` | `null` | URL for `frontend` target mode |
| `frontend_reset_url` | `AUTH_FRONTEND_RESET_URL` | `null` | URL for password-reset magic links in frontend mode |

**`method` values explained:**

- `otp` — User receives a 6-digit code, types it in a form.
- `magic_link` — User clicks a link in their email. No typing.
- `both` — User receives ONE email with both the code and the link. They can use whichever is easier. This is the default.

**`magic_link_target` explained:**

- `backend` — The magic link URL points to your own Laravel API: `GET /auth/register/verify-magic/{token}`. The package handles everything. Best for APIs.
- `frontend` — The magic link URL points to your SPA or mobile deep link. Your frontend extracts the `?token=` and calls the backend itself. Requires `AUTH_FRONTEND_VERIFY_URL` to be set.

```env
# SPA frontend mode
AUTH_MAGIC_LINK_TARGET=frontend
AUTH_FRONTEND_VERIFY_URL=https://myapp.com/verify-email
AUTH_FRONTEND_RESET_URL=https://myapp.com/reset-password
```

---

## `password_reset`

### `password_reset.method`

**Env:** `AUTH_PASSWORD_RESET_METHOD` | **Default:** `null` (inherits `verification.method`)

Controls how password reset instructions are sent, independently from registration verification. Useful when you want a different UX for each flow.

```env
# Use OTP for resets even though registration uses magic_link
AUTH_VERIFICATION_METHOD=magic_link
AUTH_PASSWORD_RESET_METHOD=otp
```

---

## `token_ttl`

Controls how long access tokens and refresh tokens last per client type. All values are in **minutes**. Set to `0` for a token that never expires.

### Mobile (`X-Client-Type: mobile` header on login)

```env
AUTH_TOKEN_TTL_MOBILE=10080    # access token: 7 days
AUTH_REFRESH_TTL_MOBILE=43200  # refresh token: 30 days
```

### SPA (browser when `AUTH_SPA_TOKEN=true`)

```env
AUTH_TOKEN_TTL_SPA=1440        # access token: 24 hours
AUTH_REFRESH_TTL_SPA=10080     # refresh token: 7 days
```

### API (`AUTH_MODE=api`)

```env
AUTH_TOKEN_TTL_API=525600      # access token: 365 days
AUTH_REFRESH_TTL_API=0         # refresh token: never expires
```

### Web session (`AUTH_MODE=web`)

No tokens are issued. The Laravel session handles everything.

```env
AUTH_SESSION_TTL=120           # keep in sync with SESSION_LIFETIME in .env
```

---

## `rate_limits`

Protects auth endpoints from brute-force and spam. Limits are applied **per IP address** and **per email** independently — both must be under the limit for the request to proceed.

**Format:** `"max_attempts:decay_minutes"` — for example `"5:1"` means 5 attempts per 1-minute window.

| Key | Env | Default | Endpoint |
|---|---|---|---|
| `register` | `AUTH_RATE_REGISTER` | `5:1` | `POST /auth/register` |
| `login` | `AUTH_RATE_LOGIN` | `5:1` | `POST /auth/login`, `POST /auth/token/refresh` |
| `otp_verify` | `AUTH_RATE_OTP_VERIFY` | `10:5` | OTP verification endpoints |
| `otp_send` | `AUTH_RATE_OTP_SEND` | `3:1` | `POST /auth/email/resend-verification` |
| `password_reset` | `AUTH_RATE_PASSWORD_RESET` | `3:1` | `POST /auth/password/forgot` |

**Recommended production values:**

```env
AUTH_RATE_LOGIN=3:5
AUTH_RATE_OTP_VERIFY=5:5
AUTH_RATE_PASSWORD_RESET=2:10
```

---

## `password`

Password policy applied at registration, password change, and password reset.

| Key | Env | Default | Meaning |
|---|---|---|---|
| `min_length` | `AUTH_PASSWORD_MIN` | `8` | Minimum character count |
| `require_uppercase` | `AUTH_PASSWORD_UPPERCASE` | `false` | Must contain at least one A–Z |
| `require_number` | `AUTH_PASSWORD_NUMBER` | `false` | Must contain at least one 0–9 |
| `require_special` | `AUTH_PASSWORD_SPECIAL` | `false` | Must contain at least one symbol (`!@#$%...`) |
| `pending_ttl_minutes` | `AUTH_PENDING_TTL` | `60` | How long a pending (unverified) registration is kept in cache. If the user does not complete verification within this window they must restart. |

**Strict production policy:**

```env
AUTH_PASSWORD_MIN=12
AUTH_PASSWORD_UPPERCASE=true
AUTH_PASSWORD_NUMBER=true
AUTH_PASSWORD_SPECIAL=true
```

---

## `roles`

Uses [Spatie Laravel Permission](https://github.com/spatie/laravel-permission).

### `roles.default_role`

**Env:** `AUTH_DEFAULT_ROLE` | **Default:** `user`

The role automatically assigned to every new user when they complete registration (after email verification). This role must already exist in your database — run `AuthRolesSeeder` or create it manually.

```env
AUTH_DEFAULT_ROLE=fan
```

> **Tip:** if your platform assigns roles based on registration type (fan vs. creator vs. admin), set `default_role` to the most common one and override it in a listener that handles `EmailVerified`.

### `roles.seeded_roles`

**Default:** `['super-admin', 'admin', 'user']`

The roles that `AuthRolesSeeder` will create. This is not an env variable. Edit it directly in `config/auth_system.php`:

```php
'seeded_roles' => ['super-admin', 'admin', 'fan', 'creator'],
```

Then run the seeder:

```bash
php artisan db:seed --class="Joe404\LaravelAuth\Database\Seeders\AuthRolesSeeder"
```

---

## `otp_channel`

### `otp_channel.driver`

**Env:** `AUTH_OTP_CHANNEL` | **Default:** `email`

Controls HOW OTP codes and magic links are delivered. The built-in `email` driver sends Blade-template emails.

To deliver via SMS, WhatsApp, or any other channel, implement `OtpChannelContract`:

```php
// app/Channels/SmsOtpChannel.php
<?php

namespace App\Channels;

use Joe404\LaravelAuth\Contracts\OtpChannelContract;

class SmsOtpChannel implements OtpChannelContract
{
    public function send(string $recipient, string $code, string $type, array $context = []): void
    {
        // $recipient = user's email (use $context['phone'] if you store it)
        // $code      = the OTP string e.g. "482910"
        // $type      = "register" | "reset"
        // $context   = ['user' => User, 'temp_token' => '...', ...]

        $phone = $context['user']->phone ?? $recipient;

        // Call your SMS provider here
        // SmsProvider::send($phone, "Your code is: {$code}");
    }
}
```

If `AUTH_VERIFICATION_METHOD=both` and your channel can deliver both OTP and magic link in one message (e.g. a rich email), also implement `CombinedOtpChannelContract`:

```php
use Joe404\LaravelAuth\Contracts\CombinedOtpChannelContract;

class MyEmailChannel implements CombinedOtpChannelContract
{
    // required by OtpChannelContract
    public function send(string $recipient, string $code, string $type, array $context = []): void
    {
        // Fallback: send OTP-only message
    }

    // called when method=both — delivers both in one message
    public function sendCombined(string $recipient, string $code, string $url, string $type, array $context = []): void
    {
        // $url = the full magic link URL
        // Send one message that contains both $code and $url
    }
}
```

> If your channel only implements `OtpChannelContract` (not `CombinedOtpChannelContract`), the package automatically falls back to calling `send()` twice when `method=both` — once for the OTP and once for the link.

```env
AUTH_OTP_CHANNEL=App\Channels\SmsOtpChannel
```

---

## `mail`

Override the built-in email notifications. All keys default to `null`, which means the package's bundled Blade template is used.

### Auth email overrides

| Key | Triggered by | Constructor signature |
|---|---|---|
| `otp_verify_notification` | OTP code sent during registration | `($code, $type, $context)` |
| `otp_reset_notification` | OTP code sent for password reset | `($code, $type, $context)` |
| `magic_link_verify_notification` | Magic link sent during registration | `($code, $type, $context)` |
| `magic_link_reset_notification` | Magic link sent for password reset | `($code, $type, $context)` |
| `otp_verify_combined_notification` | Combined OTP + link for registration (`method=both`) | `($code, $url, $type, $context)` |
| `otp_reset_combined_notification` | Combined OTP + link for password reset (`method=both`) | `($code, $url, $type, $context)` |

### Account lifecycle email overrides (v2.4)

| Key | Triggered when |
|---|---|
| `account_deleted_notification` | User soft-deletes their account |
| `account_restored_notification` | Account auto-restored on login during grace period |
| `account_purged_notification` | Account permanently purged after grace expires |
| `account_status_changed_notification` | Admin changes the account status |
| `account_deactivated_notification` | User self-deactivates (`POST /auth/account/deactivate`) |
| `account_reactivated_notification` | Account auto-reactivated on login after deactivation |

### `account_notifications_enabled`

Toggle individual lifecycle notifications on or off without removing the class pointer:

```php
'account_notifications_enabled' => [
    'deleted'        => true,   // notify user when they delete their account
    'restored'       => true,   // notify user when account is auto-restored on login
    'purged'         => false,  // no email when purge worker runs (default)
    'status_changed' => false,  // no email when admin changes status (default)
    'deactivated'    => true,   // notify user when they deactivate
    'reactivated'    => true,   // notify user when account auto-reactivates on login
],
```

**Custom notification example:**

```php
// app/Notifications/MyVerificationEmail.php
<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

class MyVerificationEmail extends Notification
{
    public function __construct(
        private readonly string $code,
        private readonly string $type,
        private readonly array  $context = []
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Verify your account')
            ->line("Your verification code is: **{$this->code}**")
            ->line('This code expires in 10 minutes.');
    }
}
```

```php
// config/auth_system.php
'mail' => [
    'otp_verify_notification' => \App\Notifications\MyVerificationEmail::class,
],
```

> You can mix and match — override only the notifications you want to change and leave the rest as `null`.

---

## `social`

### `social.google`

Enables "Sign in with Google" via Laravel Socialite.

| Key | Env | Default |
|---|---|---|
| `enabled` | `AUTH_GOOGLE_ENABLED` | `false` |
| `client_id` | `AUTH_GOOGLE_CLIENT_ID` | — |
| `client_secret` | `AUTH_GOOGLE_CLIENT_SECRET` | — |
| `redirect` | `AUTH_GOOGLE_REDIRECT` | — |

**How to get credentials:**
1. Go to [console.cloud.google.com](https://console.cloud.google.com)
2. Create a project → APIs & Services → Credentials → OAuth 2.0 Client ID
3. Set the authorized redirect URI to match `AUTH_GOOGLE_REDIRECT`

```env
AUTH_GOOGLE_ENABLED=true
AUTH_GOOGLE_CLIENT_ID=123456789.apps.googleusercontent.com
AUTH_GOOGLE_CLIENT_SECRET=GOCSPX-xxxx
AUTH_GOOGLE_REDIRECT=https://yourapp.com/auth/social/google/callback
```

### `social.frontend_url`

**Env:** `AUTH_SOCIAL_FRONTEND_URL` | **Default:** `null`

After a Google account-link confirmation is completed, the browser redirects here. When `null`, the package returns JSON instead of redirecting.

```env
AUTH_SOCIAL_FRONTEND_URL=https://yourapp.com/auth/callback
```

---

## `reverb`

### `reverb.enabled`

**Env:** `AUTH_REVERB_ENABLED` | **Default:** `false`

When `true`, the package broadcasts `EmailVerified` over WebSocket the instant a user finishes email verification. Your frontend can subscribe and react in real time — the user never needs to manually refresh or poll.

Requires [`laravel/reverb`](https://reverb.laravel.com/) to be installed and running.

**Frontend subscription** (using Laravel Echo):

```js
// Subscribe using the temp_token returned by POST /auth/register
Echo.private(`auth.verification.${tempToken}`)
    .listen('EmailVerified', (event) => {
        // The user verified! Call step 3 to complete registration.
        // Your frontend should have stored the completion_token from step 2.
        axios.post('/api/v1/auth/register/complete', {
            completion_token: storedCompletionToken,
            password: userPassword,
            password_confirmation: userPassword,
        });
    });
```

```env
AUTH_REVERB_ENABLED=true
```

---

## `api_tokens`

Long-lived, scoped API tokens for third-party integrations (scripts, CI pipelines, external services). These are separate from Sanctum session tokens and use the format `auth_at_{base64}`.

**Disabled by default** — enable only if your app specifically needs users to generate third-party tokens.

### `api_tokens.enabled`

**Env:** `AUTH_API_TOKENS_ENABLED` | **Default:** `false`

When `true`, these routes become active:

```
GET    /auth/api-tokens              list user's tokens
POST   /auth/api-tokens              create a token
DELETE /auth/api-tokens/{id}         revoke a token
GET    /auth/admin/api-tokens        admin: list all tokens
POST   /auth/admin/api-tokens        admin: create unowned token
PATCH  /auth/admin/api-tokens/{id}   admin: update token
DELETE /auth/admin/api-tokens/{id}   admin: revoke any token
```

The `CleanExpiredApiTokens` job also starts running hourly.

### `api_tokens.abilities_default`

**Default:** `['read']`

The default abilities granted to a token when none are specified in the create request.

```env
AUTH_API_TOKENS_ENABLED=true
```

---

## `queue`

Maintenance jobs the package schedules automatically.

| Job | Frequency | When active |
|---|---|---|
| `CleanExpiredOtpRecords` | Every 5 minutes | Always |
| `CleanExpiredRefreshTokens` | Hourly | Always |
| `CleanExpiredApiTokens` | Hourly | Only when `api_tokens.enabled=true` |
| `RevertExpiredAccountStatuses` | Every `auto_unban.sweep_minutes` | Only when `account.status.auto_unban.enabled=true` |

| Key | Env | Default | Meaning |
|---|---|---|---|
| `connection` | `AUTH_QUEUE_CONNECTION` | `null` | Queue connection name. `null` = use Laravel's default. |
| `name` | `AUTH_QUEUE_NAME` | `auth-maintenance` | Queue name these jobs are dispatched to. |

```env
AUTH_QUEUE_CONNECTION=redis
AUTH_QUEUE_NAME=auth-maintenance
```

Start a worker for this queue:

```bash
php artisan queue:work --queue=auth-maintenance
```

---

## `response`

### `response.formatter`

**Env:** `AUTH_RESPONSE_FORMATTER` | **Default:** `null`

Every response from the package follows this structure by default:

```json
{ "success": true,  "message": "Logged in successfully.", "data": {} }
{ "success": false, "message": "Invalid credentials.",    "errors": {} }
```

If your app uses a different envelope, implement `ResponseFormatterContract` and point to it here:

```php
// app/Auth/MyFormatter.php
<?php

namespace App\Auth;

use Joe404\LaravelAuth\Contracts\ResponseFormatterContract;

class MyFormatter implements ResponseFormatterContract
{
    public function format(bool $success, string $message, array $data = [], array $errors = []): array
    {
        // Build whatever JSON structure your frontend expects
        return [
            'ok'     => $success,
            'msg'    => $message,
            'result' => $success ? $data : $errors,
        ];
    }
}
```

Two ways to register it (package checks in this order):

**Option A — config (recommended):**

```env
AUTH_RESPONSE_FORMATTER=App\Auth\MyFormatter
```

**Option B — container binding** (in your `AppServiceProvider`):

```php
use Joe404\LaravelAuth\Contracts\ResponseFormatterContract;
use App\Auth\MyFormatter;

public function register(): void
{
    $this->app->bind(ResponseFormatterContract::class, MyFormatter::class);
}
```

---

## `security`

### `security.notify_new_device_login`

**Env:** `AUTH_NOTIFY_NEW_DEVICE` | **Default:** `true`

Sends an email alert to the user when they log in from a device (browser + OS combination) the package has not seen before. Helps users spot unauthorized access.

```env
AUTH_NOTIFY_NEW_DEVICE=true
```

### `security.lockout`

Per-account lockout after too many failed login attempts. This is separate from rate limiting. Rate limiting blocks requests by speed; lockout blocks by total failure count against a specific account.

| Key | Env | Default | Meaning |
|---|---|---|---|
| `enabled` | `AUTH_LOCKOUT_ENABLED` | `true` | Master switch |
| `max_attempts` | `AUTH_LOCKOUT_MAX` | `10` | Failed attempts before lockout |
| `decay_minutes` | `AUTH_LOCKOUT_DECAY` | `15` | How long the lockout lasts |

**Recommended production settings:**

```env
AUTH_LOCKOUT_ENABLED=true
AUTH_LOCKOUT_MAX=5
AUTH_LOCKOUT_DECAY=30
```

---

## `account`

Everything related to account status, deletion, deactivation, and audit logging (added in v2.4).

### `account.status`

Controls which statuses exist and what happens at login for each one.

| Key | Env | Default | Meaning |
|---|---|---|---|
| `enabled` | `AUTH_ACCOUNT_STATUS_ENABLED` | `true` | Master switch for the status system |
| `column` | `AUTH_ACCOUNT_STATUS_COLUMN` | `account_status` | Column on users table that stores the status string |
| `default` | `AUTH_ACCOUNT_STATUS_DEFAULT` | `active` | Status assigned to brand-new users |
| `allowed` | — | see below | List of valid status strings |
| `login_blocked` | — | `['disabled', 'suspended']` | Statuses that block login with HTTP 401 |
| `login_auto_restorable` | — | `['deactivated']` | Statuses where a successful login silently flips the user back to `active` |
| `revoke_sessions_on_change` | `AUTH_ACCOUNT_STATUS_REVOKE_ON_CHANGE` | `true` | Revoke all tokens when status leaves `active` |
| `admin_ability` | `AUTH_ACCOUNT_STATUS_ABILITY` | `super-admin\|admin` | Spatie role/permission required for admin status endpoints |

**Built-in statuses and what they mean:**

| Status | Set by | Behaviour |
|---|---|---|
| `active` | System (default) | Normal access — no restrictions |
| `suspended` | Admin | Login blocked. Can be timed (auto-lifts when `status_expires_at` passes) or permanent |
| `disabled` | Admin | Login blocked permanently. Meta-style violation ban. No expiry allowed |
| `deactivated` | User (self-service) | Login blocked but auto-reactivates when user logs in again |
| `deleted` | User or Admin | Soft-deleted. Login auto-restores within grace period. After grace the purge worker anonymises the row |

**Adding a custom status:**

```php
'allowed' => ['active', 'disabled', 'suspended', 'deleted', 'deactivated', 'under_review'],
'login_blocked' => ['disabled', 'suspended', 'under_review'],
```

#### `account.status.auto_unban`

Timed bans — when an admin sets `suspended` with an `expires_at` or `duration_minutes`, the package auto-lifts the ban two ways:

1. **Lazy revert** — checked inside `AccountStatusService::current()` on every status read (login, `auth.active` middleware, `/me`). The user can log in the instant their ban expires.
2. **Scheduled sweep** — `RevertExpiredAccountStatuses` job runs every `sweep_minutes` to catch rows the lazy path has not touched yet.

| Key | Env | Default | Meaning |
|---|---|---|---|
| `enabled` | `AUTH_ACCOUNT_AUTO_UNBAN` | `true` | Master switch for timed bans |
| `sweep_minutes` | `AUTH_ACCOUNT_AUTO_UNBAN_SWEEP` | `5` | How often the sweep worker runs |
| `temporary_statuses` | — | `['suspended']` | Which statuses accept an expiry. Passing `expires_at` for any status NOT in this list returns HTTP 422 |

```php
// Only 'suspended' can be timed. 'disabled' is always permanent.
'auto_unban' => [
    'enabled'            => true,
    'sweep_minutes'      => 5,
    'temporary_statuses' => ['suspended'],
],
```

---

### `account.deletion`

Self-service account deletion with a 30-day grace window. During the grace period, a normal login auto-restores the account. After the grace period, a scheduled worker anonymises the row.

| Key | Env | Default | Meaning |
|---|---|---|---|
| `enabled` | `AUTH_ACCOUNT_DELETE_ENABLED` | `true` | Master switch |
| `self_service` | `AUTH_ACCOUNT_DELETE_SELF` | `true` | Expose `DELETE /auth/account` for users. When `false`, only admins can mark accounts as deleted via the status endpoint |
| `require_password` | `AUTH_ACCOUNT_DELETE_REQUIRE_PASSWORD` | `true` | Force the user to supply their current password on the delete call. Strongly recommended |
| `grace_days` | `AUTH_ACCOUNT_DELETE_GRACE_DAYS` | `30` | Days the account stays soft-deleted before the worker runs |
| `auto_restore_on_login` | `AUTH_ACCOUNT_AUTO_RESTORE` | `true` | A login during grace silently restores the account and logs the user in |
| `null_uniques_after_grace` | `AUTH_ACCOUNT_NULL_UNIQUES` | `true` | After grace, null every unique column so the email/username can be reused |
| `hard_delete_after_grace` | `AUTH_ACCOUNT_HARD_DELETE` | `false` | After grace, hard-delete the users row. The `deleted_accounts` snapshot is kept regardless |
| `move_to_deleted_table` | `AUTH_ACCOUNT_AUDIT_TABLE` | `true` | Snapshot the full users row to `deleted_accounts` on delete |
| `unique_columns` | `AUTH_ACCOUNT_UNIQUE_COLUMNS` | `auto` | `auto` = introspect schema for unique indexes. Or pass a comma-separated list: `email,username` |
| `unique_exclude` | — | `['id']` | Columns to never null, even if they have a unique index |

---

### `account.deactivation`

Instagram-style "pause my account". The user can come back any time — there is no deadline.

| Key | Env | Default | Meaning |
|---|---|---|---|
| `enabled` | `AUTH_ACCOUNT_DEACTIVATE_ENABLED` | `true` | Master switch |
| `self_service` | `AUTH_ACCOUNT_DEACTIVATE_SELF` | `true` | Expose `POST /auth/account/deactivate` for users |
| `require_password` | `AUTH_ACCOUNT_DEACTIVATE_REQUIRE_PASSWORD` | `true` | Require current password confirmation on the deactivate call |
| `auto_reactivate_on_login` | `AUTH_ACCOUNT_AUTO_REACTIVATE` | `true` | Silently flip status back to `active` when the user logs in again |

**How it works end-to-end:**

1. User calls `POST /auth/account/deactivate` with their password.
2. Status set to `deactivated`. All tokens/sessions revoked immediately.
3. User logs in again any time in the future.
4. Package detects `status=deactivated` during credential validation.
5. Status silently set back to `active`. `AccountReactivatedNotification` sent. User is logged in normally.

**Difference from `disabled`:** `disabled` is an admin-only violation ban (Meta-style). It requires manual admin reactivation. A deactivated user can always bring themselves back.

---

### `account.audit`

Multi-admin audit log. Every status change and every admin note is written to `account_status_logs` with full actor/source/comment context. Multiple admins can see why an account is in its current state without contacting each other.

| Key | Env | Default | Meaning |
|---|---|---|---|
| `enabled` | `AUTH_ACCOUNT_AUDIT_ENABLED` | `true` | Master switch. When `false` the package behaves as if the audit system does not exist |
| `table` | `AUTH_ACCOUNT_AUDIT_TABLE_NAME` | `account_status_logs` | Database table name |
| `log_status_changes` | `AUTH_ACCOUNT_AUDIT_LOG_STATUS` | `true` | Write a row on every status change |
| `log_system_actions` | `AUTH_ACCOUNT_AUDIT_LOG_SYSTEM` | `true` | Write rows for system-triggered events (auto-unban, auto-restore, purge). Set `false` for a human-actions-only log |
| `capture_request_meta` | `AUTH_ACCOUNT_AUDIT_CAPTURE_META` | `true` | Save `ip_address` + `user_agent` on every write. Populated only when there is an active HTTP request |
| `retention_days` | `AUTH_ACCOUNT_AUDIT_RETENTION_DAYS` | `null` | `null` = keep forever. Any positive integer = delete entries older than N days (daily cleanup job) |

#### `account.audit.notes`

| Key | Env | Default | Meaning |
|---|---|---|---|
| `enabled` | `AUTH_ACCOUNT_AUDIT_NOTES_ENABLED` | `true` | Enable `POST /auth/admin/users/{id}/notes` — standalone admin notes without changing status |

#### `account.audit.history`

| Key | Env | Default | Meaning |
|---|---|---|---|
| `enabled` | `AUTH_ACCOUNT_AUDIT_HISTORY_ENABLED` | `true` | Enable `GET /auth/admin/users/{id}/status/history` |
| `default_per_page` | `AUTH_ACCOUNT_AUDIT_HISTORY_PER_PAGE` | `20` | Default page size |
| `max_per_page` | `AUTH_ACCOUNT_AUDIT_HISTORY_MAX_PER_PAGE` | `100` | Maximum page size a caller can request |

**Source tags written to the audit log** — so you can filter by who/what caused a change:

| Source tag | What caused it |
|---|---|
| `admin_endpoint` | Admin called `POST /auth/admin/users/{id}/status` |
| `admin_note` | Admin called `POST /auth/admin/users/{id}/notes` |
| `self_deactivate` | User called `POST /auth/account/deactivate` |
| `self_delete` | User called `DELETE /auth/account` |
| `login_auto_restore` | Login during delete grace period auto-restored the account |
| `login_auto_reactivate` | Login after deactivation auto-reactivated the account |
| `auto_unban_lazy` | Lazy revert triggered by a status read when `status_expires_at` was in the past |
| `auto_unban_sweep` | Scheduled `RevertExpiredAccountStatuses` worker ran |
| `purge_worker` | `PurgeExpiredAccountDeletions` job ran after grace expired |

---

## `errors` and `messages`

Override any success message or error message the package produces — globally, in one place, without touching translations.

### How override priority works

For **error** messages the package checks in this order:

1. `config('auth_system.errors.<key>')` — if this is set to a non-empty string, it wins. Always.
2. `trans('auth_system::errors.<key>')` — per-locale translation file (publish with `--tag=auth-lang`).
3. The exception's built-in English fallback.

For **success** messages:

1. `config('auth_system.messages.<key>')` — if set, wins.
2. `trans('auth_system::messages.<key>')` — per-locale translation file.
3. The built-in English default.

### `errors` keys

```php
'errors' => [
    // Auth
    'invalid_credentials'           => null,  // wrong email or password
    'account_inactive'              => null,  // account is not active
    'email_not_verified'            => null,  // login before email verified
    'unauthenticated'               => null,  // no valid session/token

    // OTP / registration
    'otp_invalid'                   => null,  // wrong OTP code
    'otp_expired'                   => null,  // OTP timed out
    'completion_token_invalid'      => null,  // bad/expired completion token (step 3)
    'registration_session_expired'  => null,  // pending registration cache expired
    'email_already_registered'      => null,  // email exists (sent out of band)

    // Password reset
    'reset_token_invalid'           => null,  // bad/expired reset token
    'current_password_invalid'      => null,  // wrong current password on change

    // Refresh token
    'refresh_token_invalid'         => null,
    'refresh_token_revoked'         => null,
    'refresh_token_reused'          => null,  // replay attack detected
    'refresh_token_expired'         => null,

    // API tokens
    'api_token_invalid_format'      => null,
    'api_token_invalid_encoding'    => null,
    'api_token_revoked'             => null,
    'api_token_expired'             => null,

    // Social / OAuth
    'social_provider_disabled'      => null,  // placeholder: :provider
    'social_authentication_failed'  => null,  // placeholder: :provider
    'social_email_unverified'       => null,  // placeholder: :provider
    'social_link_token_invalid'     => null,
    'social_user_not_found'         => null,

    // Sessions
    'session_not_found'             => null,

    // Lockout
    'account_locked'                => null,  // placeholder: :seconds

    // Account status / deletion (v2.4)
    'account_disabled'              => null,
    'account_suspended'             => null,
    'account_deletion_disabled'     => null,
    'account_deactivation_disabled' => null,
    'account_status_invalid'        => null,
    'account_password_mismatch'     => null,
],
```

**Placeholders** — some messages accept a `:name` placeholder that is interpolated at runtime:

| Key | Placeholder | Example output |
|---|---|---|
| `account_locked` | `:seconds` | `"Account locked. Try again in 900 seconds."` |
| `social_provider_disabled` | `:provider` | `"Google authentication is not enabled."` |
| `social_authentication_failed` | `:provider` | `"Authentication with Google failed."` |
| `social_email_unverified` | `:provider` | `"Your Google email is not verified."` |

**Example overrides:**

```php
'errors' => [
    'invalid_credentials' => 'Wrong email or password. Please try again.',
    'account_locked'      => 'Too many attempts. Wait :seconds seconds.',
    'account_suspended'   => 'Your account has been temporarily suspended.',
    'account_disabled'    => 'Your account has been permanently disabled. Contact support.',
],
```

### `messages` keys

```php
'messages' => [
    'register_initiated'     => null,  // POST /auth/register success
    'register_verified'      => null,  // after OTP/magic verification (step 2)
    'register_complete'      => null,  // after POST /auth/register/complete (step 3)
    'verification_resent'    => null,  // after POST /auth/email/resend-verification
    'login_success'          => null,  // after POST /auth/login
    'me_retrieved'           => null,  // after GET /auth/me
    'logout_success'         => null,  // after POST /auth/logout
    'logout_all_success'     => null,  // after POST /auth/logout/all
    'password_reset_sent'    => null,  // after POST /auth/password/forgot
    'password_reset_otp_ok'  => null,  // after OTP verified for password reset
    'password_reset_link_ok' => null,  // after magic link verified for password reset
    'password_reset_success' => null,  // after POST /auth/password/reset/confirm
    'password_changed'       => null,  // after POST /auth/password/change
    'sessions_retrieved'     => null,  // after GET /auth/sessions
    'session_terminated'     => null,  // after DELETE /auth/sessions/{id}
    'api_tokens_retrieved'   => null,
    'api_token_created'      => null,
    'api_token_updated'      => null,
    'api_token_revoked'      => null,
    // v2.4 account lifecycle
    'account_deleted'        => null,
    'account_restored'       => null,
    'account_status_updated' => null,
    'account_deactivated'    => null,
    'account_reactivated'    => null,
],
```

**Example overrides:**

```php
'messages' => [
    'login_success'       => 'Welcome back!',
    'register_complete'   => 'Account created! Welcome to the platform.',
    'account_deactivated' => 'Your account is now paused. See you soon.',
],
```

---

## Contracts — writing your own overrides

The package exposes six contracts in the `Joe404\LaravelAuth\Contracts\` namespace. Each one is a PHP interface you implement to replace a piece of built-in behaviour.

| Contract | Registered via | What it replaces |
|---|---|---|
| `ResponseFormatterContract` | `response.formatter` config or container binding | JSON envelope structure |
| `OtpChannelContract` | `otp_channel.driver` config | OTP/magic-link delivery (email, SMS, etc.) |
| `CombinedOtpChannelContract` | (extends `OtpChannelContract`) | Single-message combined OTP + magic link delivery |
| `ExtraFieldTransformerContract` | `registration.extra_fields_transformers` config | Deriving/normalizing extra registration fields |
| `ReferralCodeGeneratorContract` | `referral_code.generator` config | Referral code generation logic |
| `DeviceResolverContract` | Container binding | Device fingerprint parsing (platform, browser, OS) |

### `DeviceResolverContract`

Replaces the built-in User-Agent parser. Implement this when you have a better device database or a third-party device detection service.

```php
// app/Auth/MyDeviceResolver.php
<?php

namespace App\Auth;

use Illuminate\Http\Request;
use Joe404\LaravelAuth\Contracts\DeviceResolverContract;

class MyDeviceResolver implements DeviceResolverContract
{
    public function resolve(Request $request): array
    {
        // Parse $request->userAgent() however you like.
        // Return an array with these keys (all optional except 'platform'):
        return [
            'platform'               => 'mobile',    // 'mobile', 'desktop', 'tablet'
            'browser'                => 'Safari',
            'os'                     => 'iOS 17',
            'device_model'           => 'iPhone 15',
            'device_marketing_name'  => 'iPhone 15 Pro Max',
            'device_code'            => 'iPhone16,2',
            'device_platform'        => 'iOS',
        ];
    }
}
```

Register it in your `AppServiceProvider`:

```php
use Joe404\LaravelAuth\Contracts\DeviceResolverContract;
use App\Auth\MyDeviceResolver;

public function register(): void
{
    $this->app->bind(DeviceResolverContract::class, MyDeviceResolver::class);
}
```

---

## Events — hooking into the lifecycle

The package fires Laravel events at every significant moment. Your app subscribes via listeners in `app/Listeners/`. Laravel 11+ auto-discovers them — no service provider registration needed.

| Event | When fired | Payload |
|---|---|---|
| `EmailVerified` | After `POST /auth/register/complete` succeeds — user row exists, role assigned | `$user`, `$tempToken` |
| `UserLoggedIn` | Successful login (password or social) | `$user`, `$request` |
| `UserLoggedOut` | Any logout (single or all sessions) | — |
| `PasswordChanged` | Password reset or authenticated password change | `$user` |
| `SuspiciousLoginDetected` | Login from an unseen device | `$user`, `$ip`, `$browser`, `$os`, `$city`, `$country` |
| `AccountStatusChanged` | Any status change (admin, self, or system) | `$user`, `$from`, `$to`, `$source` |

**Minimal listener example:**

```php
// app/Listeners/SeedFanWallet.php
<?php

namespace App\Listeners;

use Joe404\LaravelAuth\Events\EmailVerified;

class SeedFanWallet
{
    public function handle(EmailVerified $event): void
    {
        // $event->user is the freshly created user
        Wallet::create(['user_id' => $event->user->id, 'balance' => 0]);
    }
}
```

That file alone is enough. No other registration needed.

**Queueing a listener** (for anything slow — emails, webhooks, analytics):

```php
use Illuminate\Contracts\Queue\ShouldQueue;
use Joe404\LaravelAuth\Events\EmailVerified;

class SendBrandedWelcomeEmail implements ShouldQueue
{
    public string $queue = 'mail';

    public function handle(EmailVerified $event): void
    {
        // This runs on your queue worker, not in the HTTP request
    }
}
```

**Multiple listeners on the same event** — just add more files:

```php
class SeedFanWallet          { public function handle(EmailVerified $e) {...} }
class SendBrandedWelcomeEmail { public function handle(EmailVerified $e) {...} }
class TrackSignupInAnalytics  { public function handle(EmailVerified $e) {...} }
```

All three run independently on every `EmailVerified` dispatch.

> **Warning:** do not also add `Event::listen(EmailVerified::class, ...)` in a service provider. Auto-discovery already registers it — a manual call registers it twice and your handler runs twice.

---

## Complete `.env` reference

Every environment variable the package reads, in one place.

```env
# ── Core ──────────────────────────────────────────────────────────────────────
AUTH_MODE=both                         # api | web | both
AUTH_SPA_TOKEN=false
AUTH_REQUIRE_VERIFICATION=true

# ── Routes ────────────────────────────────────────────────────────────────────
AUTH_ROUTES_REGISTER=true
AUTH_ROUTES_PREFIX=auth                # change to api/v1/auth for versioned APIs

# ── Verification ──────────────────────────────────────────────────────────────
AUTH_VERIFICATION_METHOD=both          # otp | magic_link | both
AUTH_OTP_LENGTH=6
AUTH_OTP_EXPIRY=10
AUTH_OTP_MAX_ATTEMPTS=5
AUTH_MAGIC_EXPIRY=30
AUTH_MAGIC_LINK_TARGET=backend         # backend | frontend
AUTH_FRONTEND_VERIFY_URL=
AUTH_FRONTEND_RESET_URL=
AUTH_OTP_CHANNEL=email                 # email | App\Channels\YourChannel

# ── Password reset ────────────────────────────────────────────────────────────
AUTH_PASSWORD_RESET_METHOD=            # null inherits AUTH_VERIFICATION_METHOD

# ── Password policy ───────────────────────────────────────────────────────────
AUTH_PASSWORD_MIN=8
AUTH_PASSWORD_UPPERCASE=false
AUTH_PASSWORD_NUMBER=false
AUTH_PASSWORD_SPECIAL=false
AUTH_PENDING_TTL=60                    # minutes to keep pending registration in cache

# ── Token TTLs (in minutes) ───────────────────────────────────────────────────
AUTH_TOKEN_TTL_MOBILE=10080            # 7 days
AUTH_REFRESH_TTL_MOBILE=43200          # 30 days
AUTH_TOKEN_TTL_SPA=1440                # 24 hours
AUTH_REFRESH_TTL_SPA=10080             # 7 days
AUTH_TOKEN_TTL_API=525600              # 365 days
AUTH_REFRESH_TTL_API=0                 # 0 = never expires
AUTH_SESSION_TTL=120                   # keep in sync with SESSION_LIFETIME

# ── Rate limiting ─────────────────────────────────────────────────────────────
AUTH_RATE_REGISTER=5:1
AUTH_RATE_LOGIN=5:1
AUTH_RATE_OTP_VERIFY=10:5
AUTH_RATE_OTP_SEND=3:1
AUTH_RATE_PASSWORD_RESET=3:1

# ── Roles ─────────────────────────────────────────────────────────────────────
AUTH_DEFAULT_ROLE=user

# ── Referral codes ────────────────────────────────────────────────────────────
AUTH_REFERRAL_CODE_ENABLED=false
AUTH_REFERRAL_CODE_COLUMN=referral_code
AUTH_REFERRAL_CODE_LENGTH=10
AUTH_REFERRAL_CODE_UPPERCASE=true
AUTH_REFERRAL_CODE_GENERATOR=          # FQCN of custom generator class

# ── Social / Google OAuth ─────────────────────────────────────────────────────
AUTH_GOOGLE_ENABLED=false
AUTH_GOOGLE_CLIENT_ID=
AUTH_GOOGLE_CLIENT_SECRET=
AUTH_GOOGLE_REDIRECT=
AUTH_SOCIAL_FRONTEND_URL=

# ── Reverb (WebSocket) ────────────────────────────────────────────────────────
AUTH_REVERB_ENABLED=false

# ── API tokens ────────────────────────────────────────────────────────────────
AUTH_API_TOKENS_ENABLED=false

# ── Queue ─────────────────────────────────────────────────────────────────────
AUTH_QUEUE_CONNECTION=                 # null = use app default
AUTH_QUEUE_NAME=auth-maintenance

# ── Response formatter ────────────────────────────────────────────────────────
AUTH_RESPONSE_FORMATTER=               # FQCN of custom formatter class

# ── Security ──────────────────────────────────────────────────────────────────
AUTH_NOTIFY_NEW_DEVICE=true
AUTH_LOCKOUT_ENABLED=true
AUTH_LOCKOUT_MAX=10
AUTH_LOCKOUT_DECAY=15

# ── Account status (v2.4) ─────────────────────────────────────────────────────
AUTH_ACCOUNT_STATUS_ENABLED=true
AUTH_ACCOUNT_STATUS_COLUMN=account_status
AUTH_ACCOUNT_STATUS_DEFAULT=active
AUTH_ACCOUNT_STATUS_REVOKE_ON_CHANGE=true
AUTH_ACCOUNT_STATUS_ABILITY=super-admin|admin
AUTH_ACCOUNT_AUTO_UNBAN=true
AUTH_ACCOUNT_AUTO_UNBAN_SWEEP=5        # minutes between sweep worker runs

# ── Account deletion (v2.4) ───────────────────────────────────────────────────
AUTH_ACCOUNT_DELETE_ENABLED=true
AUTH_ACCOUNT_DELETE_SELF=true
AUTH_ACCOUNT_DELETE_REQUIRE_PASSWORD=true
AUTH_ACCOUNT_DELETE_GRACE_DAYS=30
AUTH_ACCOUNT_AUTO_RESTORE=true
AUTH_ACCOUNT_NULL_UNIQUES=true
AUTH_ACCOUNT_HARD_DELETE=false
AUTH_ACCOUNT_AUDIT_TABLE=true          # snapshot to deleted_accounts on delete
AUTH_ACCOUNT_UNIQUE_COLUMNS=auto       # auto | comma,separated,column,names

# ── Account deactivation (v2.4) ───────────────────────────────────────────────
AUTH_ACCOUNT_DEACTIVATE_ENABLED=true
AUTH_ACCOUNT_DEACTIVATE_SELF=true
AUTH_ACCOUNT_DEACTIVATE_REQUIRE_PASSWORD=true
AUTH_ACCOUNT_AUTO_REACTIVATE=true

# ── Audit log (v2.4) ──────────────────────────────────────────────────────────
AUTH_ACCOUNT_AUDIT_ENABLED=true
AUTH_ACCOUNT_AUDIT_TABLE_NAME=account_status_logs
AUTH_ACCOUNT_AUDIT_LOG_STATUS=true
AUTH_ACCOUNT_AUDIT_LOG_SYSTEM=true
AUTH_ACCOUNT_AUDIT_CAPTURE_META=true
AUTH_ACCOUNT_AUDIT_RETENTION_DAYS=     # null = keep forever, integer = days
AUTH_ACCOUNT_AUDIT_NOTES_ENABLED=true
AUTH_ACCOUNT_AUDIT_HISTORY_ENABLED=true
AUTH_ACCOUNT_AUDIT_HISTORY_PER_PAGE=20
AUTH_ACCOUNT_AUDIT_HISTORY_MAX_PER_PAGE=100
```
