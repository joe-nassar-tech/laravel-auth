# joe-404/laravel-auth

A drop-in, config-driven authentication library for Laravel 13. Register, verify, log in, reset passwords, manage sessions, issue API tokens, and sign in with Google — all through a single JSON API with zero frontend coupling.

[![Latest Version on Packagist](https://img.shields.io/packagist/v/joe-404/laravel-auth.svg?style=flat-square)](https://packagist.org/packages/joe-404/laravel-auth)
[![PHP](https://img.shields.io/badge/PHP-8.2%2B-blue?style=flat-square)](https://php.net)
[![Laravel](https://img.shields.io/badge/Laravel-12%2B%20%7C%2013%2B-red?style=flat-square)](https://laravel.com)
[![License](https://img.shields.io/badge/license-MIT-green?style=flat-square)](LICENSE)

---

## Table of Contents

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Quick Start](#quick-start)
- [Authentication Modes](#authentication-modes)
- [API Reference](#api-reference)
  - [Registration](#registration)
  - [Login & Logout](#login--logout)
  - [Token Refresh](#token-refresh)
  - [Password Reset](#password-reset)
  - [Password Change](#password-change)
  - [Sessions](#sessions)
  - [Google OAuth](#google-oauth)
  - [API Tokens](#api-tokens)
- [Configuration](#configuration)
- [Customization](#customization)
  - [Extra Registration Fields](#extra-registration-fields)
  - [Referral Codes (v2.2)](#referral-codes-v22)
  - [Custom Response Messages (v2.2)](#custom-response-messages-v22)
  - [Extra-field Validation Messages (v2.2)](#extra-field-validation-messages-v22)
  - [Extra-field Transformers (v2.2)](#extra-field-transformers-v22)
  - [Multi-language Support (v2.3)](#multi-language-support-v23)
  - [Custom OTP Channel (SMS, WhatsApp…)](#custom-otp-channel-sms-whatsapp)
  - [Custom Response Format](#custom-response-format)
  - [Custom Email Templates](#custom-email-templates)
- [Events](#events)
- [Security Design](#security-design)
- [Testing](#testing)
- [License](#license)

---

## Features

| Feature | Details |
|---|---|
| **Registration** | Email-only initiation → OTP or magic-link verification → password set after email proof |
| **Email verification** | OTP code, magic link, or both in a single email |
| **Login** | Password-based; session cookie or Bearer token depending on auth mode |
| **Token refresh** | Dedicated refresh tokens (separate table, one-time-use, atomic rotation) |
| **Password reset** | OTP or magic link; independently configurable from registration |
| **Password change** | Authenticated; optionally revokes all other sessions |
| **Session management** | Track device, browser, OS, IP, city, country; revoke individual or all sessions |
| **Google OAuth** | Sign-in with Google; safe account-linking with inbox-confirmation email |
| **API tokens** | Long-lived, scoped tokens for third-party integrations (opt-in feature) |
| **Rate limiting** | Per-IP + per-email; independently configurable per endpoint |
| **Account lockout** | Temporary lockout after repeated failed login attempts |
| **New device alerts** | Email notification when a user logs in from an unrecognised browser/OS |
| **Reverb WebSocket** | Optional real-time verification status push |
| **Response envelope** | Uniform `{ success, message, data }` / `{ success, message, errors }` on every response |

---

## Requirements

| Dependency | Version |
|---|---|
| PHP | `^8.2` |
| Laravel | `^12.0` or `^13.0` |
| Laravel Sanctum | `^4.0` |
| Laravel Socialite | `^5.0` |
| Spatie Permission | `^6.0` |
| Redis (recommended) | phpredis or predis |

---

## Installation

### 1. Install via Composer

```bash
composer require joe-404/laravel-auth
```

### 2. Run the installer

```bash
php artisan auth:install
```

This command publishes the config file, migrations, and email views, and walks you through the minimal `.env` setup.

### 3. Run migrations

```bash
php artisan migrate
```

Six tables are created:

| Table | Purpose |
|---|---|
| `auth_otp_codes` | OTP codes and magic-link tokens (stored as SHA-256 hashes) |
| `auth_sessions_extended` | Device and session tracking per user |
| `auth_refresh_tokens` | Refresh tokens (hashed, separate from Sanctum access tokens) |
| `auth_social_accounts` | Linked OAuth provider accounts |
| `auth_api_tokens` | Long-lived API tokens (when feature is enabled) |
| `users` (altered) | Adds `last_login_at` and `is_active` columns |

### 4. Seed roles

```bash
php artisan db:seed --class="Joe404\LaravelAuth\Database\Seeders\AuthRolesSeeder"
```

Creates `super-admin`, `admin`, and `user` roles via Spatie Permission.

### 5. Configure Sanctum

In `config/sanctum.php`, make sure your frontend domain is in the `stateful` list:

```php
'stateful' => explode(',', env('SANCTUM_STATEFUL_DOMAINS', 'localhost,127.0.0.1')),
```

---

## Quick Start

### Minimal `.env`

```env
AUTH_MODE=both
AUTH_VERIFICATION_METHOD=both

MAIL_MAILER=smtp
MAIL_FROM_ADDRESS=hello@yourapp.com
MAIL_FROM_NAME="${APP_NAME}"
```

### End-to-end example with curl

```bash
# 1. Initiate registration (email only — no password yet)
curl -sX POST http://localhost/auth/register \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"email":"user@example.com"}'
# → { "data": { "temp_token": "uuid", "method": "both", "expires_in": 10 } }

# 2. Verify with the OTP code sent to the email
curl -sX POST http://localhost/auth/register/verify-otp \
  -H "Content-Type: application/json" \
  -d '{"email":"user@example.com","otp":"482910"}'
# → { "data": { "completion_token": "uuid" } }

# 3. Set password and complete registration
curl -sX POST http://localhost/auth/register/complete \
  -H "Content-Type: application/json" \
  -d '{"completion_token":"uuid","password":"Secret123!","password_confirmation":"Secret123!"}'
# → { "data": { "user": {...}, "token": "1|abc...", "refresh_token": "xyz..." } }

# 4. Login
curl -sX POST http://localhost/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"user@example.com","password":"Secret123!"}'
# → { "data": { "user": {...}, "token": "...", "refresh_token": "..." } }
```

---

## Authentication Modes

Set `AUTH_MODE` to control how credentials are issued after login:

| Mode | Behaviour | Best for |
|---|---|---|
| `web` | Session cookie only, no tokens | Traditional web apps, cookie-based SPAs |
| `api` | Bearer token always | Pure API backends, mobile apps |
| `both` | Auto-detect per request (default) | One backend serving both mobile and browser |

**How `both` mode detects the client:**

1. Request has `X-Client-Type: mobile` header → issues Bearer token (mobile TTL)
2. `AUTH_SPA_TOKEN=true` and no `X-Client-Type` → issues Bearer token (SPA TTL)
3. Otherwise → sets a session cookie (no token)

In **web** and **both (session)** modes, SPA clients should:
- Call `GET /sanctum/csrf-cookie` first
- Send the CSRF cookie (`X-XSRF-TOKEN` header) with all state-mutating requests

---

## API Reference

All routes are prefixed with `/auth`. All responses use the envelope:

```json
// Success
{ "success": true, "message": "...", "data": { ... } }

// Error
{ "success": false, "message": "...", "errors": { "field": ["..."] } }
```

---

### Registration

Registration is a **three-step flow** that proves email ownership before accepting a password, preventing pre-account takeover attacks.

#### Step 1 — Initiate

```
POST /auth/register
```

Accepts `email` plus any extra fields defined in `auth_system.registration.extra_fields_rules`.

**Request**

```json
{
  "email": "user@example.com"
}
```

**Response** `201`

```json
{
  "success": true,
  "message": "Verification sent. Please check your email.",
  "data": {
    "temp_token": "550e8400-e29b-41d4-a716-446655440000",
    "method": "both",
    "expires_in": 10
  }
}
```

- `temp_token` — subscribe to `Echo.private("auth.verification.{temp_token}")` for real-time status (requires Reverb)
- `method` — `"otp"`, `"magic_link"`, or `"both"`
- `expires_in` — minutes until the OTP expires

---

#### Step 2a — Verify with OTP

```
POST /auth/register/verify-otp
```

**Request**

```json
{
  "email": "user@example.com",
  "otp": "482910"
}
```

**Response** `200`

```json
{
  "success": true,
  "message": "Email verified. Please set your password.",
  "data": {
    "completion_token": "a7f3d9c2-1234-5678-abcd-ef0123456789"
  }
}
```

---

#### Step 2b — Verify with magic link

The user clicks the link in their email. The library handles this route automatically.

```
GET /auth/register/verify-magic/{token}
```

Returns the same `{ "completion_token": "..." }` payload as OTP verification.

> **Frontend target mode**: when `AUTH_MAGIC_LINK_TARGET=frontend`, the email link points to `AUTH_FRONTEND_VERIFY_URL?token=xxx`. Your frontend extracts the token and calls `GET /auth/register/verify-magic/{token}` itself.

---

#### Step 3 — Complete registration

```
POST /auth/register/complete
```

**Request**

```json
{
  "completion_token": "a7f3d9c2-1234-5678-abcd-ef0123456789",
  "password": "Secret123!",
  "password_confirmation": "Secret123!"
}
```

**Response** `201`

```json
{
  "success": true,
  "message": "Registration complete.",
  "data": {
    "user": { "id": 1, "name": "user", "email": "user@example.com" },
    "token": "1|abc123...",
    "refresh_token": "def456..."
  }
}
```

`token` and `refresh_token` are `null` in web/session mode; the session cookie is set automatically.

---

#### Resend verification

```
POST /auth/email/resend-verification
```

**Request**

```json
{ "email": "user@example.com" }
```

Always returns success to prevent email enumeration.

---

### Login & Logout

#### Login

```
POST /auth/login
```

**Request**

```json
{
  "email": "user@example.com",
  "password": "Secret123!"
}
```

Add `X-Client-Type: mobile` to receive a Bearer token in `both` mode.

**Response** `200`

```json
{
  "success": true,
  "message": "Login successful.",
  "data": {
    "user": { "id": 1, "email": "user@example.com" },
    "token": "1|abc123...",
    "refresh_token": "def456..."
  }
}
```

**Error codes**

| HTTP | Reason |
|---|---|
| `401` | Invalid credentials |
| `403` | Account inactive or email not verified |
| `423` | Account locked out |
| `429` | Rate limit exceeded |

---

#### Logout

```
POST /auth/logout
```

Revokes the current token (and its paired refresh token) or invalidates the session. Requires authentication.

---

#### Logout all devices

```
POST /auth/logout/all
```

Revokes all tokens and sessions for the authenticated user. The current session is preserved.

---

#### Get current user

```
GET /auth/me
```

**Response** `200`

```json
{
  "success": true,
  "data": {
    "user": { "id": 1, "email": "user@example.com" },
    "roles": ["user"],
    "permissions": ["read:posts"],
    "active_sessions": 3
  }
}
```

---

### Token Refresh

Exchange an expired access token for a new pair. The old refresh token is consumed atomically (one-time use). Concurrent refresh requests are safe.

```
POST /auth/token/refresh
```

**Request**

```json
{ "refresh_token": "def456..." }
```

**Response** `200`

```json
{
  "success": true,
  "data": {
    "user": { "..." },
    "token": "2|xyz789...",
    "refresh_token": "ghi012..."
  }
}
```

**Error codes**

| HTTP | Reason |
|---|---|
| `401` | Token invalid or already consumed |
| `401` | Token expired — user must log in again |

---

### Password Reset

#### Step 1 — Request reset

```
POST /auth/password/forgot
```

**Request**

```json
{ "email": "user@example.com" }
```

Always returns success (prevents enumeration). Sends OTP or magic link per `AUTH_PASSWORD_RESET_METHOD`.

---

#### Step 2a — Verify OTP

```
POST /auth/password/reset/verify-otp
```

**Request**

```json
{
  "email": "user@example.com",
  "otp": "719283"
}
```

**Response** `200`

```json
{
  "success": true,
  "message": "OTP verified. Submit your new password using the reset_token.",
  "data": { "reset_token": "a7f3d9c2-1234-5678-abcd-ef0123456789" }
}
```

The `reset_token` is valid for **15 minutes** and is consumed once by Step 3.

**Error codes**

| HTTP | Reason |
|---|---|
| `422` | OTP invalid or wrong code |
| `422` | OTP expired — request a new reset email |

---

#### Step 2b — Verify with magic link

User clicks the link in their email. The library validates the signature and issues a `reset_token`.

```
GET /auth/password/reset/magic/{token}
```

**Response** `200`

```json
{
  "success": true,
  "message": "Link validated. Submit your new password using the reset_token.",
  "data": { "reset_token": "a7f3d9c2-1234-5678-abcd-ef0123456789" }
}
```

> **Frontend target mode**: when `AUTH_MAGIC_LINK_TARGET=frontend`, the email link points to `AUTH_FRONTEND_RESET_URL?token=xxx`. Your frontend extracts the token and calls `GET /auth/password/reset/magic/{token}` itself.

---

#### Step 3 — Confirm reset

Both Step 2a (OTP) and Step 2b (magic link) produce the same `reset_token`. Use it here to set the new password. The user is **automatically logged in** on success — the response mirrors the login response.

```
POST /auth/password/reset/confirm
```

**Request**

```json
{
  "reset_token": "a7f3d9c2-1234-5678-abcd-ef0123456789",
  "password": "NewSecret123!",
  "password_confirmation": "NewSecret123!",
  "logout_all": true
}
```

- `logout_all` (default `true`) — when `true`, all existing sessions and tokens are revoked before the new session is created; when `false`, existing sessions are preserved and only the password is changed

**Response** `200` — mobile / API client (`X-Client-Type: mobile`)

```json
{
  "success": true,
  "message": "Password reset successfully. You are now logged in.",
  "data": {
    "user": { "id": 1, "email": "user@example.com" },
    "token": "3|xYzAbC...",
    "refresh_token": "ghi012..."
  }
}
```

**Response** `200` — web / SPA client (session cookie mode)

```json
{
  "success": true,
  "message": "Password reset successfully. You are now logged in.",
  "data": {
    "user": { "id": 1, "email": "user@example.com" },
    "token": null,
    "refresh_token": null
  }
}
```

Session cookie is set automatically in web/SPA mode. Client-type detection follows the same rules as the login endpoint (`AUTH_MODE`, `X-Client-Type` header, `AUTH_SPA_TOKEN`).

**Error codes**

| HTTP | Reason |
|---|---|
| `422` | `reset_token` invalid or expired |
| `422` | Password does not meet policy |

---

### Password Change

Requires authentication.

```
POST /auth/password/change
```

**Request**

```json
{
  "current_password": "Secret123!",
  "new_password": "NewSecret456!",
  "new_password_confirmation": "NewSecret456!",
  "logout_all": true
}
```

- `logout_all` — if `true`, all other sessions and tokens are revoked; the current session is preserved

---

### Sessions

Requires authentication.

#### List sessions

```
GET /auth/sessions
```

**Response** `200`

```json
{
  "success": true,
  "data": {
    "sessions": [
      {
        "id": 1,
        "platform": "web",
        "browser": "Chrome",
        "os": "Windows",
        "ip_address": "203.0.113.10",
        "city": "Beirut",
        "country": "LB",
        "last_active_at": "2026-05-09T14:23:00Z",
        "is_current": true
      }
    ]
  }
}
```

#### Revoke a session

```
DELETE /auth/sessions/{id}
```

Returns `403` if the session does not belong to the authenticated user.

---

### Google OAuth

#### Step 1 — Get redirect URL

```
GET /auth/social/google/redirect
```

- Browser clients get a `302` redirect to Google
- JSON/XHR clients get `{ "redirect_url": "https://accounts.google.com/..." }`

#### Step 2 — Handle callback

Google redirects back to:

```
GET /auth/social/google/callback
```

**Happy path** (existing or new account):

```json
{
  "success": true,
  "message": "Logged in with Google successfully.",
  "data": {
    "user": { "..." },
    "token": "1|abc123...",
    "refresh_token": "def456..."
  }
}
```

**Account-linking required** (Google email matches an existing local account but no social link exists):

```json
{
  "success": true,
  "message": "An email was sent to confirm linking your account.",
  "data": { "email": "user@example.com" }
}
```

A signed confirmation email is sent. The user clicks it to approve the link — the library never auto-links based on email match alone.

#### Step 3 — Confirm account link (email click)

```
GET /auth/social/{provider}/link/confirm/{token}
```

After confirming, the user is logged in and redirected to `AUTH_SOCIAL_FRONTEND_URL` (or a JSON response if not set).

**Required `.env`**

```env
AUTH_GOOGLE_ENABLED=true
AUTH_GOOGLE_CLIENT_ID=123456789.apps.googleusercontent.com
AUTH_GOOGLE_CLIENT_SECRET=GOCSPX-xxxx
AUTH_GOOGLE_REDIRECT=https://yourapp.com/auth/social/google/callback
AUTH_SOCIAL_FRONTEND_URL=https://yourapp.com/auth/callback   # optional
```

---

### API Tokens

Long-lived, scoped tokens for third-party integrations (CI/CD pipelines, scripts, external services). **Disabled by default** — enable with `AUTH_API_TOKENS_ENABLED=true`.

These tokens use the format `auth_at_{base64}`, are stored in `auth_api_tokens`, and are completely separate from Sanctum session tokens.

#### User endpoints (requires auth)

```
GET    /auth/api-tokens           # List your tokens
POST   /auth/api-tokens           # Create a token
DELETE /auth/api-tokens/{id}      # Revoke a token
```

**Create a token — Request**

```json
{
  "name": "My CI Pipeline",
  "abilities": ["read", "deploy"],
  "expires_in_days": 90
}
```

**Response** `201`

```json
{
  "success": true,
  "data": {
    "raw_token": "auth_at_...",
    "token": { "id": 1, "name": "My CI Pipeline", "abilities": ["read","deploy"], "expires_at": "2026-08-07T00:00:00Z" }
  }
}
```

> Store `raw_token` securely — it is shown only once.

#### Admin endpoints (requires `super-admin` or `admin` role)

```
GET    /auth/admin/api-tokens          # List all tokens
POST   /auth/admin/api-tokens          # Create a system-level token
PATCH  /auth/admin/api-tokens/{id}     # Update abilities / expiry
DELETE /auth/admin/api-tokens/{id}     # Revoke any token
```

---

## Configuration

After running `php artisan auth:install`, edit `config/auth_system.php`. Every option has a corresponding `.env` variable.

### Complete `.env` reference

```env
# ── Core ─────────────────────────────────────────────────────────────────────
AUTH_MODE=both                        # api | web | both (default: both)
AUTH_SPA_TOKEN=false                  # true = SPA clients get Bearer token in 'both' mode
AUTH_REQUIRE_VERIFICATION=true        # false = allow login without email verification

# ── Verification ─────────────────────────────────────────────────────────────
AUTH_VERIFICATION_METHOD=both         # otp | magic_link | both
AUTH_OTP_LENGTH=6                     # digits in the OTP code (4–8)
AUTH_OTP_EXPIRY=10                    # minutes until OTP expires
AUTH_OTP_MAX_ATTEMPTS=5               # wrong guesses before OTP is invalidated
AUTH_MAGIC_EXPIRY=30                  # minutes until magic link expires
AUTH_MAGIC_LINK_TARGET=backend        # backend | frontend
AUTH_FRONTEND_VERIFY_URL=             # e.g. https://yourapp.com/verify-email
AUTH_FRONTEND_RESET_URL=              # e.g. https://yourapp.com/reset-password
AUTH_PENDING_TTL=60                   # minutes to keep pending registration in cache

# ── Password Reset ────────────────────────────────────────────────────────────
AUTH_PASSWORD_RESET_METHOD=           # null = inherit AUTH_VERIFICATION_METHOD
                                      # or: otp | magic_link | both

# ── Password Policy ───────────────────────────────────────────────────────────
AUTH_PASSWORD_MIN=8                   # minimum password length
AUTH_PASSWORD_UPPERCASE=false         # require at least one uppercase letter
AUTH_PASSWORD_NUMBER=false            # require at least one digit
AUTH_PASSWORD_SPECIAL=false           # require at least one symbol

# ── Token TTL (in minutes) ────────────────────────────────────────────────────
AUTH_TOKEN_TTL_MOBILE=10080           # mobile access token — 7 days
AUTH_REFRESH_TTL_MOBILE=43200         # mobile refresh token — 30 days
AUTH_TOKEN_TTL_SPA=1440               # SPA access token — 24 hours
AUTH_REFRESH_TTL_SPA=10080            # SPA refresh token — 7 days
AUTH_TOKEN_TTL_API=525600             # API access token — 365 days
AUTH_REFRESH_TTL_API=0                # API refresh token — 0 = never expires

# ── Rate Limits (format: "max_attempts:decay_minutes") ───────────────────────
AUTH_RATE_REGISTER=5:1                # 5 registrations per minute
AUTH_RATE_LOGIN=5:1                   # 5 login attempts per minute
AUTH_RATE_OTP_VERIFY=10:5             # 10 OTP guesses per 5 minutes
AUTH_RATE_OTP_SEND=3:1                # 3 OTP resend requests per minute
AUTH_RATE_PASSWORD_RESET=3:1          # 3 reset requests per minute

# ── Security ─────────────────────────────────────────────────────────────────
AUTH_NOTIFY_NEW_DEVICE=true           # email alert on new browser/OS login
AUTH_LOCKOUT_ENABLED=true             # lock account after repeated failures
AUTH_LOCKOUT_MAX=10                   # failed attempts before lockout
AUTH_LOCKOUT_DECAY=15                 # minutes the lockout lasts

# ── Roles ────────────────────────────────────────────────────────────────────
AUTH_DEFAULT_ROLE=user                # role auto-assigned on registration

# ── OTP delivery channel ──────────────────────────────────────────────────────
AUTH_OTP_CHANNEL=email                # email (default) | App\Channels\SmsOtpChannel

# ── Google OAuth ─────────────────────────────────────────────────────────────
AUTH_GOOGLE_ENABLED=false
AUTH_GOOGLE_CLIENT_ID=123456789.apps.googleusercontent.com
AUTH_GOOGLE_CLIENT_SECRET=GOCSPX-xxxx
AUTH_GOOGLE_REDIRECT=https://yourapp.com/auth/social/google/callback
AUTH_SOCIAL_FRONTEND_URL=             # redirect target after social link confirmation

# ── API Tokens ────────────────────────────────────────────────────────────────
AUTH_API_TOKENS_ENABLED=false         # enable the long-lived API token system

# ── Queue ────────────────────────────────────────────────────────────────────
AUTH_QUEUE_CONNECTION=                # null = app default queue
AUTH_QUEUE_NAME=auth-maintenance      # queue name for maintenance jobs

# ── Reverb (real-time WebSocket) ──────────────────────────────────────────────
AUTH_REVERB_ENABLED=false

# ── Response formatter ────────────────────────────────────────────────────────
AUTH_RESPONSE_FORMATTER=              # null = default { success, message, data }
```

---

## Customization

### Extra Registration Fields

Add custom fields to registration without touching library code.

**Option A — simple rule strings** (in `config/auth_system.php`):

```php
'registration' => [
    'extra_fields_rules' => [
        'phone'   => 'required|string|max:20',
        'country' => 'required|string|size:2',
    ],
],
```

Ensure the field names are in your `User` model's `$fillable` array. Their validated values are passed directly to `User::create()`.

**Option B — custom FormRequest** (for complex rules, custom messages):

```php
// app/Http/Requests/MyRegisterRequest.php
use Joe404\LaravelAuth\Http\Requests\RegisterRequest;
use Illuminate\Validation\Rule;

class MyRegisterRequest extends RegisterRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'phone' => ['required', 'string', Rule::unique('users')],
        ]);
    }

    public function messages(): array
    {
        return [
            'phone.unique' => 'That phone number is already registered.',
        ];
    }
}
```

```php
// config/auth_system.php
'registration' => [
    'request_class' => \App\Http\Requests\MyRegisterRequest::class,
],
```

---

### Referral Codes (v2.2)

Generate a unique referral code per user during finalize-registration and persist it on the configured column.

```php
'referral_code' => [
    'enabled'   => env('AUTH_REFERRAL_CODE_ENABLED', true),
    'column'    => 'referral_code',
    'length'    => 8,
    'uppercase' => true,
    'generator' => null,            // FQCN of a ReferralCodeGeneratorContract impl
],
```

- Disabled by default. Enable per-app via env or config.
- Won't overwrite a value the user already supplied via `extra_fields_rules`.
- Swap the generator class to control formatting (human-friendly slugs, prefixed codes, etc.).

See `docs/customization.md` for the contract, custom-generator example, and the required migration column.

---

### Custom Response Messages (v2.2)

Every controller success message is overridable per key:

```php
'messages' => [
    'register_initiated' => 'Check your inbox for a verification code.',
    'login_success'      => 'Welcome back!',
    'logout_success'     => null,  // keep built-in default
],
```

- `null` (default) keeps the package's built-in English.
- Resolution order: config → `trans('auth_system::messages.*')` → built-in fallback.
- Full key list and worked examples in `docs/customization.md` and `docs/localization.md`.

---

### Extra-field Validation Messages (v2.2)

Pair custom rules with Laravel-style per-rule messages without subclassing the request:

```php
'registration' => [
    'extra_fields_rules' => [
        'username'      => 'required|string|min:3|alpha_dash',
        'date_of_birth' => 'required|date|before:18 years ago',
    ],
    'extra_fields_messages' => [
        'username.required'    => 'Pick a username before you continue.',
        'date_of_birth.before' => 'You must be at least 18 years old to register.',
    ],
],
```

Errors come back in the standard 422 envelope under `errors.<field>`.

---

### Extra-field Transformers (v2.2)

Derive a target field from validated input — `username` → `username_normalized`, for example — without writing a controller:

```php
use Joe404\LaravelAuth\Contracts\ExtraFieldTransformerContract;

final class UsernameLowercaseTransformer implements ExtraFieldTransformerContract
{
    public function transform(array $validated): mixed
    {
        return strtolower(trim((string) ($validated['username'] ?? '')));
    }
}
```

```php
'registration' => [
    'extra_fields_transformers' => [
        'username_normalized' => \App\Auth\UsernameLowercaseTransformer::class,
    ],
],
```

The transformer runs after validation and before user creation. Output still goes through the same privileged-field denylist that protects raw input — transformers cannot set `role`, `password`, `is_admin`, etc.

---

### Multi-language Support (v2.3)

Every user-facing string the package returns — **success messages and error messages alike** — now flows through Laravel's translation system, on top of the same per-key config override.

**Resolution order** (success and errors both):

1. `config('auth_system.messages.<key>')` / `config('auth_system.errors.<key>')` — static override (wins if set).
2. `trans('auth_system::messages.<key>')` / `trans('auth_system::errors.<key>')` — per-locale.
3. The built-in English fallback.

**Quick start:**

```bash
# Publish the package's English/Arabic language files
php artisan vendor:publish --tag=auth-lang
```

This drops the files at `lang/vendor/auth_system/<locale>/...` (Laravel 9+) or `resources/lang/vendor/auth_system/<locale>/...` (older).

Add a new locale (e.g. French):

```php
// lang/vendor/auth_system/fr/messages.php
return [
    'register_initiated' => 'Vérification envoyée. Veuillez vérifier vos e-mails.',
    'login_success'      => 'Connecté avec succès.',
    // … rest of the 19 keys …
];
```

```php
// lang/vendor/auth_system/fr/errors.php
return [
    'invalid_credentials' => 'Identifiants invalides.',
    'account_inactive'    => 'Votre compte est désactivé.',
    // … rest of the 26 keys …
];
```

Switch locale however your app already does (`app()->setLocale('fr')`, an `Accept-Language` middleware, route prefix, etc.) — the package reads `app()->getLocale()` at response time.

**Placeholders** use Laravel's standard `:name` syntax:

| Key | Placeholder |
|-----|-------------|
| `account_locked` | `:seconds` |
| `social_provider_disabled` | `:provider` |
| `social_authentication_failed` | `:provider` |
| `social_email_unverified` | `:provider` |

**Sample request** (same code, two locales):

```bash
curl -X POST /auth/login -H 'Accept-Language: en' -d '{...wrong...}'
# {"success":false,"message":"Invalid credentials.","data":{},"errors":{}}

curl -X POST /auth/login -H 'Accept-Language: ar' -d '{...wrong...}'
# {"success":false,"message":"بيانات الاعتماد غير صحيحة.","data":{},"errors":{}}
```

Full key reference, RTL example, and end-to-end walkthrough: **[docs/localization.md](docs/localization.md)**.

---

### Custom OTP Channel (SMS, WhatsApp…)

Replace the built-in email delivery with any channel by implementing `OtpChannelContract`:

```php
use Joe404\LaravelAuth\Contracts\OtpChannelContract;

class SmsOtpChannel implements OtpChannelContract
{
    public function send(string $recipient, string $code, string $type, array $context = []): void
    {
        // $code  — plain OTP digits (e.g. "482910") or the full magic link URL
        // $type  — 'email_verify' | 'magic_link_verify' | 'password_reset' | 'magic_link_reset'
        TwilioClient::messages->create($recipient, [
            'from' => config('services.twilio.from'),
            'body' => "Your code: {$code}",
        ]);
    }
}
```

For channels that can combine OTP + magic link into a single message, implement `CombinedOtpChannelContract`:

```php
use Joe404\LaravelAuth\Contracts\CombinedOtpChannelContract;

class WhatsAppChannel implements CombinedOtpChannelContract
{
    public function send(string $recipient, string $code, string $type, array $context = []): void
    {
        // Fallback: code-only delivery
    }

    public function sendCombined(string $recipient, string $code, string $url, string $type, array $context = []): void
    {
        // Single message with both the OTP code and the clickable magic link
    }
}
```

Register in config:

```php
// config/auth_system.php
'otp_channel' => [
    'driver' => \App\Channels\SmsOtpChannel::class,
],
```

---

### Custom Response Format

Every response passes through a formatter. Swap the envelope to match your API conventions:

```php
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

Register via config (recommended) or service container:

```php
// Option 1 — config/auth_system.php
'response' => [
    'formatter' => \App\Auth\MyFormatter::class,
],

// Option 2 — AppServiceProvider (config takes priority)
use Joe404\LaravelAuth\Contracts\ResponseFormatterContract;
$this->app->bind(ResponseFormatterContract::class, \App\Auth\MyFormatter::class);
```

---

### Custom Email Templates

**Option 1 — Blade views** (recommended for styling):

```bash
php artisan vendor:publish --tag=auth-views
```

Edit files in `resources/views/vendor/laravel-auth/emails/`:

| File | Email |
|---|---|
| `otp-verify.blade.php` | OTP code — registration |
| `otp-reset.blade.php` | OTP code — password reset |
| `magic-link-verify.blade.php` | Magic link — registration |
| `magic-link-reset.blade.php` | Magic link — password reset |
| `otp-verify-combined.blade.php` | OTP + magic link in one email (method=both, registration) |
| `otp-reset-combined.blade.php` | OTP + magic link in one email (method=both, password reset) |

**Option 2 — custom notification class** (for full control over the mailable):

Your class receives `($code, $type, $context)` in its constructor. For combined: `($code, $url, $type, $context)`.

```php
// config/auth_system.php
'mail' => [
    'otp_reset_notification' => \App\Notifications\MyResetEmail::class,
],
```

You can mix — override only specific emails and let the library handle the rest.

---

## Events

| Event | When fired | Payload |
|---|---|---|
| `UserRegistered` | Registration initiated | `$user` |
| `EmailVerified` | Email verified and account created | `$user`, `$completionToken` |
| `UserLoggedIn` | Successful login | `$user`, `$request` |
| `UserLoggedOut` | Any logout | — |
| `PasswordChanged` | Password reset or changed | `$user` |
| `SuspiciousLoginDetected` | Login from unrecognised device | `$user`, `$ip`, `$browser`, `$os`, `$city`, `$country` |

**Example — send a welcome email after registration:**

```php
use Joe404\LaravelAuth\Events\EmailVerified;
use Illuminate\Support\Facades\Event;
use App\Mail\WelcomeMail;
use Illuminate\Support\Facades\Mail;

Event::listen(EmailVerified::class, function (EmailVerified $event): void {
    Mail::to($event->user)->send(new WelcomeMail($event->user));
});
```

---

## Security Design

### Registration — no pre-account takeover

The three-step flow ensures that the person who proves email ownership is the one who sets the password:

1. **Initiate** — only the email is captured; nothing sensitive is cached
2. **Verify** — proves inbox access; issues a `completion_token` (15-min TTL)
3. **Complete** — whoever holds the `completion_token` sets the password

An attacker who registers with a victim's email receives a `temp_token` but never gets the `completion_token` — that is given to whoever completes the inbox challenge. The victim who clicks the verification link sets their own password. Neither party can impersonate the other.

### Token storage

All tokens are stored as SHA-256 hashes — plain values are never written to the database:

| Token | Table | Type |
|---|---|---|
| Access token | `personal_access_tokens` | Sanctum SHA-256 |
| Refresh token | `auth_refresh_tokens` | SHA-256, one-time use |
| OTP code | `auth_otp_codes` | SHA-256 |
| Magic link UUID | `auth_otp_codes` | SHA-256 |
| API token | `auth_api_tokens` | SHA-256 |

### Refresh token rotation

Refresh tokens live in a dedicated `auth_refresh_tokens` table, completely separate from Sanctum's `personal_access_tokens`. Each token is:
- **One-time use** — consumed via `DB::transaction()` + `SELECT FOR UPDATE` to prevent concurrent reuse
- **Paired** — each refresh token points to exactly one access token; only that pair is revoked on rotation

### OTP brute-force protection

Each wrong OTP guess increments a `failed_attempts` counter on the active OTP row (atomic `INCREMENT` to prevent races). When the counter reaches `AUTH_OTP_MAX_ATTEMPTS` (default 5), the OTP is invalidated and the user must request a new one.

### Rate limiting

All auth endpoints are rate-limited per IP **and** per email address independently. Exceeding either limit returns HTTP 429.

### Account lockout

Repeated failed login attempts trigger a time-limited lockout (`AUTH_LOCKOUT_MAX` failures → locked for `AUTH_LOCKOUT_DECAY` minutes). This is separate from rate limiting — it persists even across slow, distributed attempts.

### OAuth account linking

When a Google account's email matches an existing local account, the library **never auto-links** based on email alone. A signed confirmation email is sent to the registered address; only after the legitimate inbox owner clicks the link is the social account linked, preventing account takeover via OAuth email spoofing.

---

## Testing

```bash
composer test
```

The test suite uses Pest with `RefreshDatabase`, `Mail::fake()`, and `Queue::fake()`. No real emails or HTTP calls are made.

---

## License

MIT. See [LICENSE](LICENSE).
