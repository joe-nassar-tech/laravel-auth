# joe-404/laravel-auth

[![Latest Version on Packagist](https://img.shields.io/packagist/v/joe-404/laravel-auth.svg?style=flat-square)](https://packagist.org/packages/joe-404/laravel-auth)
[![PHP Version](https://img.shields.io/badge/PHP-%5E8.2-blue?style=flat-square)](https://packagist.org/packages/joe-404/laravel-auth)
[![Laravel Version](https://img.shields.io/badge/Laravel-%5E13.0-red?style=flat-square)](https://packagist.org/packages/joe-404/laravel-auth)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg?style=flat-square)](https://opensource.org/licenses/MIT)
[![Tests](https://img.shields.io/badge/tests-Pest-purple?style=flat-square)](https://pestphp.com)

**A drop-in, config-driven authentication library for Laravel 13.**

Install once, configure via `.env`, and get a production-ready authentication system with:

- **Registration** with OTP + magic-link email verification (sent simultaneously)
- **Login / Logout** — Bearer token (API), session cookie (SPA), or auto-detected (`both` mode)
- **Password reset** via OTP or signed magic link
- **Session & device tracking** — browser, OS, device model, IP, city, country
- **API token system** — scoped, optionally expiring tokens for third-party clients
- **Google OAuth** via Laravel Socialite
- **Real-time verification** broadcast via Laravel Reverb
- **Security hardening** — dual-layer rate limiting, account lockout, new-device email alerts
- **100% JSON API** — consistent `{ success, message, data }` envelope on every response

> **One `composer require`. One `php artisan auth:install`. Zero boilerplate.**

---

## Why joe-404/laravel-auth?

| What you'd normally build manually | What this package gives you |
|---|---|
| Registration + OTP + magic link logic | Single `POST /auth/register` with both sent simultaneously |
| Custom session/device fingerprinting | Built-in `auth_sessions_extended` table + `X-Device-Info` header |
| Rate limiting per IP **and** per email | Configured per endpoint via `.env` |
| Account lockout across rate windows | Cumulative Redis counter, independent of per-window limits |
| API token system (scoped, expiring) | Full CRUD + `ApiTokenAuth` middleware with ability checks |
| Google OAuth → link or create user | `GET /auth/social/google/callback` handles all three cases |
| Real-time auth events in the browser | `EmailVerified` broadcast on `auth.verification.{temp_token}` |
| Custom response envelope | `ResponseFormatterContract` — override without touching library code |
| Custom OTP delivery (SMS, WhatsApp) | `OtpChannelContract` — swap the channel in one line |

---

## Table of Contents

1. [Requirements](#requirements)
2. [Installation](#installation)
3. [Configuration Reference](#configuration-reference)
4. [Authentication Modes](#authentication-modes)
5. [API Endpoints](#api-endpoints)
   - [Registration](#registration)
   - [Email Verification](#email-verification)
   - [Login](#login)
   - [Logout](#logout)
   - [Current User](#current-user)
   - [Password Reset](#password-reset)
   - [Password Change](#password-change)
   - [Session Management](#session-management)
   - [API Token Management](#api-token-management)
   - [Google OAuth](#google-oauth)
6. [Response Envelope](#response-envelope)
7. [Customising the Response Format](#customising-the-response-format)
8. [Customising the OTP Channel](#customising-the-otp-channel)
9. [Security Features](#security-features)
   - [Rate Limiting](#rate-limiting)
   - [Account Lockout](#account-lockout)
   - [New Device Detection](#new-device-detection)
10. [Real-time Verification (Reverb)](#real-time-verification-reverb)
11. [Device & Session Tracking](#device--session-tracking)
12. [Admin API Token Management](#admin-api-token-management)
13. [Role Assignment](#role-assignment)
14. [Events Reference](#events-reference)
15. [Scheduled Jobs](#scheduled-jobs)
16. [Environment Variable Quick Reference](#environment-variable-quick-reference)
17. [Extending the Library](#extending-the-library)

---

## Requirements

| Dependency | Version |
|---|---|
| PHP | `^8.2` |
| Laravel | `^13.0` |
| laravel/sanctum | `^4.0` |
| laravel/socialite | `^5.0` |
| spatie/laravel-permission | `^6.0` |
| jenssegers/agent | `^2.6` |
| A cache driver | Redis recommended, `array` for testing |

---

## Installation

### Step 1 — Require the package

```bash
composer require joe-404/laravel-auth
```

### Step 2 — Run the install command

```bash
php artisan auth:install
```

This command:
- Publishes `config/auth_system.php`
- Publishes all database migrations into `database/migrations/`
- Publishes the role seeder into `database/seeders/`
- Appends the Reverb channel stub to `routes/channels.php` (for real-time verification)
- Prints a checklist of next steps

### Step 3 — Run migrations

```bash
php artisan migrate
```

The package creates or modifies the following tables:

| Table | Purpose |
|---|---|
| `users` (modified) | Adds `google_id`, `is_active`, `last_login_at` columns |
| `auth_otp_codes` | Stores OTP and magic-link verification tokens |
| `auth_sessions_extended` | Device and session tracking per user |
| `auth_social_accounts` | Google OAuth account links |
| `auth_api_tokens` | Third-party API token management |

### Step 4 — Seed roles

```bash
php artisan db:seed --class=AuthRolesSeeder
```

This creates the three built-in roles: `super-admin`, `admin`, `user`.

### Step 5 — Configure your `.env`

At a minimum, add:

```dotenv
AUTH_MODE=api           # api | web | both
AUTH_GOOGLE_ENABLED=false
AUTH_REVERB_ENABLED=false
```

---

## Configuration Reference

After publishing, the full config lives in `config/auth_system.php`. Every key maps to an environment variable. Below is a detailed explanation of every option.

---

### `mode`

**Env:** `AUTH_MODE` | **Default:** `both`

Controls how authentication tokens are issued.

| Value | Behaviour |
|---|---|
| `api` | Always issues a Sanctum **Bearer token**. Every response includes `data.token`. |
| `web` | Uses Laravel **session cookies** only. `data.token` is always `null`. |
| `both` | Auto-detects: sends a Bearer token when the request contains `X-Client-Type: mobile` header or `Accept: application/json`. Falls back to session cookie for browser requests. |

**Example:**
```dotenv
AUTH_MODE=api
```

---

### `verification`

Controls email verification after registration.

#### `verification.method`

**Env:** `AUTH_VERIFICATION_METHOD` | **Default:** `both`

| Value | Behaviour |
|---|---|
| `otp` | Sends a numeric OTP code to the user's email. User verifies by POSTing the code. |
| `magic_link` | Sends a signed URL to the user's email. User clicks the link to verify. |
| `both` | Sends **both** simultaneously. User uses whichever arrives first. |

#### `verification.otp_length`

**Env:** `AUTH_OTP_LENGTH` | **Default:** `6`

Number of digits in the OTP code. Accepted values: `4`–`8`.

#### `verification.otp_expiry`

**Env:** `AUTH_OTP_EXPIRY` | **Default:** `10`

Minutes before the OTP code expires.

#### `verification.magic_expiry`

**Env:** `AUTH_MAGIC_EXPIRY` | **Default:** `30`

Minutes before the magic link URL expires.

**Example:**
```dotenv
AUTH_VERIFICATION_METHOD=otp
AUTH_OTP_LENGTH=6
AUTH_OTP_EXPIRY=10
AUTH_MAGIC_EXPIRY=30
```

---

### `token`

#### `token.expiration_minutes`

**Env:** `AUTH_TOKEN_EXPIRY` | **Default:** `10080` (7 days)

How long a Sanctum Bearer token remains valid. Set to `0` for no expiry.

**Example:**
```dotenv
AUTH_TOKEN_EXPIRY=1440   # 24 hours
```

---

### `rate_limits`

Protects endpoints against abuse. Format is `"max_attempts:decay_minutes"`.

| Key | Env | Default | Protects |
|---|---|---|---|
| `register` | `AUTH_RATE_REGISTER` | `5:1` | `POST /auth/register` |
| `login` | `AUTH_RATE_LOGIN` | `5:1` | `POST /auth/login` |
| `otp_send` | `AUTH_RATE_OTP_SEND` | `3:1` | `POST /auth/email/resend-verification` |
| `password_reset` | `AUTH_RATE_PASSWORD_RESET` | `3:1` | `POST /auth/password/forgot` |

Rate limiting checks both **IP address** and **email address** independently. If either is over the limit the request is blocked with HTTP 429.

**Example — stricter login:**
```dotenv
AUTH_RATE_LOGIN=3:5    # 3 attempts per 5 minutes
```

---

### `password`

#### `password.min_length`

**Env:** `AUTH_PASSWORD_MIN` | **Default:** `8`

Minimum password length enforced at the request validation layer.

#### `password.require_uppercase`

**Env:** `AUTH_PASSWORD_UPPERCASE` | **Default:** `false`

Require at least one uppercase letter.

#### `password.require_number`

**Env:** `AUTH_PASSWORD_NUMBER` | **Default:** `false`

Require at least one number.

#### `password.require_special`

**Env:** `AUTH_PASSWORD_SPECIAL` | **Default:** `false`

Require at least one special character.

#### `password.pending_ttl_minutes`

**Env:** `AUTH_PENDING_TTL` | **Default:** `60`

How long (in minutes) the pre-registration cache entry lives. During registration, the user's password hash is stored in cache until email verification is complete. If they do not verify within this window, they must register again.

**Example:**
```dotenv
AUTH_PASSWORD_MIN=12
AUTH_PASSWORD_UPPERCASE=true
AUTH_PASSWORD_NUMBER=true
AUTH_PENDING_TTL=30
```

---

### `require_email_verification`

**Env:** `AUTH_REQUIRE_VERIFICATION` | **Default:** `true`

When `true`, users must verify their email before they can log in. Setting this to `false` allows login immediately after registration (useful for internal tools).

```dotenv
AUTH_REQUIRE_VERIFICATION=false
```

---

### `roles`

#### `roles.default_role`

**Env:** `AUTH_DEFAULT_ROLE` | **Default:** `user`

The Spatie role name automatically assigned to every new user (both via standard registration and Google OAuth).

#### `roles.seeded_roles`

Array of roles created by `AuthRolesSeeder`. Default: `['super-admin', 'admin', 'user']`. Edit the seeder to add custom roles.

**Example:**
```dotenv
AUTH_DEFAULT_ROLE=member
```

---

### `otp_channel`

#### `otp_channel.driver`

**Env:** `AUTH_OTP_CHANNEL` | **Default:** `email`

The channel used to deliver OTP codes and magic links. Set to `email` to use the built-in `EmailOtpChannel`, or provide a fully-qualified class name that implements `Joe404\LaravelAuth\Contracts\OtpChannelContract` to use SMS, WhatsApp, etc.

See [Customising the OTP Channel](#customising-the-otp-channel).

---

### `social`

#### `social.google.enabled`

**Env:** `AUTH_GOOGLE_ENABLED` | **Default:** `false`

Enables the Google OAuth endpoints. Also requires:

```dotenv
AUTH_GOOGLE_ENABLED=true
GOOGLE_CLIENT_ID=your-client-id
GOOGLE_CLIENT_SECRET=your-client-secret
GOOGLE_REDIRECT_URI=https://your-app.com/auth/social/google/callback
```

The library auto-configures `services.google` from these values — you do not need to modify `config/services.php`.

---

### `reverb`

#### `reverb.enabled`

**Env:** `AUTH_REVERB_ENABLED` | **Default:** `false`

When `true` and Laravel Reverb is installed, the library broadcasts an `EmailVerified` event on the private channel `auth.verification.{temp_token}` when a user completes email verification. This allows your frontend to react in real-time without polling.

See [Real-time Verification](#real-time-verification-reverb).

---

### `queue`

#### `queue.connection`

**Env:** `AUTH_QUEUE_CONNECTION` | **Default:** `null` (uses app default)

Queue connection for background maintenance jobs.

#### `queue.name`

**Env:** `AUTH_QUEUE_NAME` | **Default:** `auth-maintenance`

Queue name for background maintenance jobs.

---

### `response`

#### `response.formatter`

**Env:** `AUTH_RESPONSE_FORMATTER` | **Default:** `null`

Fully-qualified class name of a custom response formatter. See [Customising the Response Format](#customising-the-response-format).

---

### `security`

#### `security.notify_new_device_login`

**Env:** `AUTH_NOTIFY_NEW_DEVICE` | **Default:** `true`

When `true`, sends an email notification to the user when they log in from a device (browser + OS combination) that has not been seen before. Uses the `NewDeviceLoginNotification` mailable.

#### `security.lockout.enabled`

**Env:** `AUTH_LOCKOUT_ENABLED` | **Default:** `true`

Enables account-level lockout. Unlike rate limiting (which blocks by request rate), account lockout tracks cumulative failures across multiple rate-limit windows. Once `max_attempts` are reached, the account is locked for `decay_minutes` regardless of IP address.

#### `security.lockout.max_attempts`

**Env:** `AUTH_LOCKOUT_MAX` | **Default:** `10`

Number of failed login attempts before the account is locked.

#### `security.lockout.decay_minutes`

**Env:** `AUTH_LOCKOUT_DECAY` | **Default:** `15`

How long the lockout lasts in minutes.

**Example:**
```dotenv
AUTH_NOTIFY_NEW_DEVICE=true
AUTH_LOCKOUT_ENABLED=true
AUTH_LOCKOUT_MAX=5
AUTH_LOCKOUT_DECAY=30
```

---

## Authentication Modes

The library serves three client types through a single `auth:sanctum` guard:

| Client type | How to authenticate |
|---|---|
| SPA (browser) | Laravel session cookie. No `Authorization` header needed. |
| Mobile / API | `Authorization: Bearer {token}` header on every request. |
| Third-party services | `Authorization: Bearer auth_at_{token}` — uses the API token system, not Sanctum. |

For `AUTH_MODE=both`, the library auto-detects the client type:
- Requests with `X-Client-Type: mobile` header → Bearer token
- Requests with `Accept: application/json` → Bearer token
- Everything else → session cookie

---

## API Endpoints

All routes are prefixed with `/auth`. Base URL example: `https://your-app.com/auth`.

All requests and responses use `Content-Type: application/json`.

---

### Registration

#### `POST /auth/register`

Initiates registration. Sends OTP and/or magic link to the provided email. Does **not** create a user record yet — that happens on verification.

**Request body:**

| Field | Type | Required | Description |
|---|---|---|---|
| `email` | string | Yes | User's email address |
| `password` | string | Yes | Min 8 characters |
| `password_confirmation` | string | Yes | Must match `password` |

**Success response — 201:**
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

| Field | Description |
|---|---|
| `temp_token` | UUID used to subscribe to the Reverb real-time channel `auth.verification.{temp_token}` |
| `method` | Which verification method(s) were sent (`otp`, `magic_link`, or `both`) |
| `expires_in` | Minutes until the OTP/link expires |

**Error responses:**

| Status | When |
|---|---|
| 409 | Email already registered |
| 422 | Validation failed |
| 429 | Rate limit exceeded |

---

#### `POST /auth/register/verify-otp`

Completes registration by submitting the OTP code received by email.

**Request body:**

| Field | Type | Required | Description |
|---|---|---|---|
| `email` | string | Yes | Same email used in `/register` |
| `otp` | string | Yes | The numeric code from the email |

**Success response — 201:**
```json
{
  "success": true,
  "message": "Registration complete.",
  "data": {
    "user": { "id": 1, "name": "joe", "email": "joe@example.com", "..." },
    "token": "1|abc123...",
    "temp_token": "550e8400-..."
  }
}
```

`token` is `null` when `AUTH_MODE=web`.

**Error responses:**

| Status | When |
|---|---|
| 422 | OTP is wrong or expired |

---

#### `GET /auth/register/verify-magic/{token}`

Completes registration by clicking the magic link from the email. The `{token}` is the UUID embedded in the link by the library — users never construct this URL manually; they simply click the link in their inbox.

**Success response — 201:** Same structure as `verify-otp`.

**Error responses:**

| Status | When |
|---|---|
| 422 | Link signature is invalid, link is expired, or token was already used |

---

### Email Verification

#### `POST /auth/email/resend-verification`

Resends OTP and/or magic link for a registration that has not yet been verified. Generates a new `temp_token` (invalidating the previous Reverb subscription). Always returns HTTP 200 regardless of whether the email exists — this prevents email enumeration.

**Request body:**

| Field | Type | Required |
|---|---|---|
| `email` | string | Yes |

**Success response — 200:**
```json
{
  "success": true,
  "message": "If a pending registration exists for that email, a new verification has been sent.",
  "data": {}
}
```

---

### Login

#### `POST /auth/login`

Authenticates an existing, verified user.

**Request body:**

| Field | Type | Required |
|---|---|---|
| `email` | string | Yes |
| `password` | string | Yes |

**Optional headers:**

| Header | Value | Effect |
|---|---|---|
| `X-Client-Type` | `mobile` | Forces Bearer token response even in `AUTH_MODE=both` |
| `X-Device-Info` | JSON string (see below) | Identifies mobile device for session tracking |

**`X-Device-Info` JSON format (mobile clients):**
```json
{
  "model": "SM-G991B",
  "platform": "android",
  "os_version": "14"
}
```

**Success response — 200:**
```json
{
  "success": true,
  "message": "Logged in successfully.",
  "data": {
    "user": {
      "id": 1,
      "name": "Joe",
      "email": "joe@example.com",
      "email_verified_at": "2025-01-01T00:00:00.000000Z",
      "last_login_at": "2025-05-08T12:00:00.000000Z"
    },
    "token": "2|xyz789..."
  }
}
```

`token` is `null` for web session logins.

**Error responses:**

| Status | When |
|---|---|
| 401 | Wrong email or password; or account locked out |
| 403 | Account inactive (`is_active = false`) |
| 403 | Email not verified (`require_email_verification = true`) |
| 422 | Validation failed |
| 429 | Rate limit exceeded |

---

### Logout

All logout endpoints require authentication (`Authorization: Bearer {token}` or active session cookie).

#### `POST /auth/logout`

Revokes the current token/session only.

**Success response — 200:**
```json
{
  "success": true,
  "message": "Logged out successfully.",
  "data": {}
}
```

#### `POST /auth/logout/all`

Revokes all sessions and tokens for the authenticated user across all devices.

**Success response — 200:**
```json
{
  "success": true,
  "message": "All sessions have been terminated.",
  "data": {}
}
```

---

### Current User

#### `GET /auth/me`

Returns the authenticated user's profile, roles, permissions, and active session count.

**Requires:** Authentication.

**Success response — 200:**
```json
{
  "success": true,
  "message": "User retrieved.",
  "data": {
    "user": { "id": 1, "name": "Joe", "email": "joe@example.com" },
    "roles": ["user"],
    "permissions": ["read-posts", "create-posts"],
    "active_sessions": 2
  }
}
```

---

### Password Reset

Password reset is a two-step process: request → verify → set new password.

#### Step 1 — `POST /auth/password/forgot`

Sends OTP and/or magic link to the email. Always returns 200 to prevent email enumeration.

**Request body:**

| Field | Type | Required |
|---|---|---|
| `email` | string | Yes |

**Success response — 200:**
```json
{
  "success": true,
  "message": "If that email is registered, you will receive reset instructions shortly.",
  "data": {}
}
```

---

#### Step 2a — Reset via OTP: `POST /auth/password/reset/otp`

Submit the OTP from the email along with the new password in one call.

**Request body:**

| Field | Type | Required |
|---|---|---|
| `email` | string | Yes |
| `otp` | string | Yes | Numeric code from email |
| `password` | string | Yes | New password (min 8 chars) |
| `password_confirmation` | string | Yes |

**Success response — 200:**
```json
{
  "success": true,
  "message": "Password reset successfully. Please log in with your new password.",
  "data": {}
}
```

**Error responses:**

| Status | When |
|---|---|
| 422 | OTP invalid, expired, or user not found |

---

#### Step 2b — Reset via magic link (two parts):

**Part 1 — `GET /auth/password/reset/magic/{token}`**

The user clicks the link in their email. This validates the signed URL and returns a short-lived `reset_token` UUID.

**Success response — 200:**
```json
{
  "success": true,
  "message": "Link validated. Submit your new password using the reset_token.",
  "data": {
    "reset_token": "a1b2c3d4-e5f6-..."
  }
}
```

The `reset_token` is valid for **15 minutes**.

**Part 2 — `POST /auth/password/reset/confirm`**

Submit the `reset_token` with the new password.

**Request body:**

| Field | Type | Required |
|---|---|---|
| `reset_token` | string (UUID) | Yes | From step 2b Part 1 |
| `password` | string | Yes |
| `password_confirmation` | string | Yes |

**Success response — 200:**
```json
{
  "success": true,
  "message": "Password reset successfully. Please log in with your new password.",
  "data": {}
}
```

> After a successful password reset, **all existing tokens and sessions are revoked** automatically.

---

### Password Change

#### `POST /auth/password/change`

Changes the password for the currently authenticated user.

**Requires:** Authentication.

**Request body:**

| Field | Type | Required | Description |
|---|---|---|---|
| `current_password` | string | Yes | Must match the stored hash |
| `new_password` | string | Yes | Min 8 chars, must differ from current |
| `new_password_confirmation` | string | Yes |
| `logout_all` | boolean | No | When `true`, revokes all other sessions/tokens (keeps current session active) |

**Success response — 200:**
```json
{
  "success": true,
  "message": "Password changed successfully.",
  "data": {}
}
```

---

### Session Management

#### `GET /auth/sessions`

Lists all active sessions for the authenticated user.

**Requires:** Authentication.

**Success response — 200:**
```json
{
  "success": true,
  "message": "Sessions retrieved.",
  "data": {
    "sessions": [
      {
        "id": 1,
        "platform": "api",
        "browser": "Chrome",
        "os": "Windows",
        "device_model": null,
        "device_marketing_name": null,
        "ip_address": "203.0.113.1",
        "country": "Lebanon",
        "city": "Beirut",
        "last_active_at": "2025-05-08T12:00:00.000000Z",
        "is_current": true
      }
    ]
  }
}
```

#### `DELETE /auth/sessions/{id}`

Revokes a specific session by its ID.

**Requires:** Authentication.

**Success response — 200:**
```json
{
  "success": true,
  "message": "Session terminated.",
  "data": {}
}
```

---

### API Token Management

API tokens are long-lived, scoped tokens for third-party integrations (CI pipelines, external services, mobile SDKs). They are distinct from Sanctum session tokens.

Token format: `auth_at_{base64_encoded_random}`

> Only the SHA-256 hash of the raw token is stored. **Show the raw token to the user once immediately after creation** — it cannot be recovered.

#### `GET /auth/api-tokens`

Lists all API tokens owned by the authenticated user.

**Requires:** Authentication + `AUTH_MODE` must be `api` or `both`.

**Success response — 200:**
```json
{
  "success": true,
  "message": "API tokens retrieved.",
  "data": {
    "tokens": [
      {
        "id": 1,
        "name": "CI Pipeline",
        "abilities": ["read", "deploy"],
        "last_used_at": "2025-05-07T09:00:00Z",
        "expires_at": null,
        "is_active": true
      }
    ]
  }
}
```

#### `POST /auth/api-tokens`

Creates a new API token.

**Requires:** Authentication + `AUTH_MODE` must be `api` or `both`.

**Request body:**

| Field | Type | Required | Description |
|---|---|---|---|
| `name` | string | Yes | Human-readable label |
| `abilities` | array of strings | No | Defaults to `["read"]`. Use `["*"]` for full access. |
| `expires_in_days` | integer | No | `null` = never expires. Max 3650. |

**Success response — 201:**
```json
{
  "success": true,
  "message": "API token created. Store it securely — it will not be shown again.",
  "data": {
    "raw_token": "auth_at_dGhpcyBpcyBhIHRlc3Q...",
    "token": {
      "id": 1,
      "name": "CI Pipeline",
      "abilities": ["read", "deploy"],
      "expires_at": null,
      "is_active": true
    }
  }
}
```

#### `DELETE /auth/api-tokens/{id}`

Revokes one of the authenticated user's API tokens.

**Requires:** Authentication.

**Success response — 200:**
```json
{
  "success": true,
  "message": "API token revoked.",
  "data": {}
}
```

---

#### Using an API Token

Pass it in the `Authorization` header on requests to your own application endpoints. Your application middleware must include `auth.api-token`:

```
Authorization: Bearer auth_at_dGhpcyBpcyBhIHRlc3Q...
```

Add the middleware to your routes:

```php
// Require any valid API token
Route::middleware('auth.api-token')->group(function () { ... });

// Require specific abilities
Route::middleware('auth.api-token:read,orders')->group(function () { ... });
```

Inside the controller, the resolved token is available as:
```php
$request->get('_api_token');  // AuthApiToken model
```

---

### Google OAuth

#### `GET /auth/social/google/redirect`

Returns the Google OAuth authorization URL. Your frontend should redirect the user to this URL.

**Requires:** `AUTH_GOOGLE_ENABLED=true`.

**Success response — 200:**
```json
{
  "success": true,
  "message": "Redirect URL generated.",
  "data": {
    "redirect_url": "https://accounts.google.com/o/oauth2/auth?client_id=..."
  }
}
```

**Error response:**

| Status | When |
|---|---|
| 403 | Google OAuth is disabled in config |

#### `GET /auth/social/google/callback`

Google redirects the user to this URL after authorization. The library handles the OAuth exchange automatically.

**Three cases:**

| Case | What happens |
|---|---|
| Provider ID matches an existing `auth_social_accounts` record | The user is logged in |
| No provider ID match but email matches an existing user | Google account is linked to the existing user, then logged in |
| Brand new user | A new user account is created (email pre-verified), role assigned, then logged in |

**Success response — 200:**
```json
{
  "success": true,
  "message": "Authenticated via Google.",
  "data": {
    "user": { "id": 3, "email": "joe@gmail.com", "email_verified_at": "2025-05-08T12:00:00Z" },
    "token": "3|abc..."
  }
}
```

**Error responses:**

| Status | When |
|---|---|
| 403 | Google OAuth disabled, or account is inactive |
| 400 | OAuth exchange failed (Google error) |

---

## Response Envelope

Every response from this library follows the same JSON structure:

**Success:**
```json
{
  "success": true,
  "message": "Human-readable description.",
  "data": { }
}
```

**Failure:**
```json
{
  "success": false,
  "message": "Human-readable error description.",
  "errors": { }
}
```

---

## Customising the Response Format

If your application uses a different JSON structure (e.g., wrapping in `{ "status": "ok", "result": {} }`), implement the `ResponseFormatterContract`:

```php
namespace App\Auth;

use Joe404\LaravelAuth\Contracts\ResponseFormatterContract;

class MyFormatter implements ResponseFormatterContract
{
    public function format(bool $success, string $message, array $data, array $errors): array
    {
        return [
            'status'  => $success ? 'ok' : 'error',
            'message' => $message,
            'result'  => $success ? $data : $errors,
        ];
    }
}
```

Then register it — two ways (first one wins):

**Option A — config:**
```dotenv
AUTH_RESPONSE_FORMATTER=App\Auth\MyFormatter
```

**Option B — service container (in `AppServiceProvider`):**
```php
$this->app->bind(
    \Joe404\LaravelAuth\Contracts\ResponseFormatterContract::class,
    \App\Auth\MyFormatter::class,
);
```

---

## Customising the OTP Channel

By default OTP codes and magic links are delivered via email. To use SMS or another channel, implement `OtpChannelContract`:

```php
namespace App\Auth;

use Joe404\LaravelAuth\Contracts\OtpChannelContract;

class SmsOtpChannel implements OtpChannelContract
{
    public function sendOtp(string $email, string $code, array $context = []): void
    {
        // Look up phone number by email, send SMS
    }

    public function sendMagicLink(string $email, string $url, array $context = []): void
    {
        // Send the link via SMS
    }
}
```

Register it:
```dotenv
AUTH_OTP_CHANNEL=App\Auth\SmsOtpChannel
```

---

## Security Features

### Rate Limiting

All public auth endpoints are protected by the `auth.ratelimit` middleware. Rate limits apply independently per **IP address** and per **email address**. If either is over the limit, a `429 Too Many Requests` response is returned with a `Retry-After` header.

The response on rate limit:
```json
{
  "success": false,
  "message": "Too many attempts. Please try again in 42 seconds.",
  "errors": {}
}
```

Rate limits are cleared automatically on a successful response.

To customise limits:
```dotenv
AUTH_RATE_LOGIN=3:5         # 3 attempts per 5 minutes
AUTH_RATE_REGISTER=10:1     # 10 per minute
AUTH_RATE_OTP_SEND=2:5      # 2 resend attempts per 5 minutes
AUTH_RATE_PASSWORD_RESET=2:10
```

---

### Account Lockout

Account lockout is a second, independent layer on top of rate limiting. Where rate limiting blocks within a sliding window, lockout tracks **cumulative failures across all windows** for a specific email address.

Flow:
1. User fails login → failure counter incremented in cache (key: `auth:lockout_count:{sha1(email)}`)
2. Counter resets after `AUTH_LOCKOUT_DECAY` minutes of inactivity
3. After `AUTH_LOCKOUT_MAX` total failures, a lockout flag is set (key: `auth:locked:{sha1(email)}`) for `AUTH_LOCKOUT_DECAY` minutes
4. Any login attempt during lockout (including correct credentials) returns 401 with the locked message
5. Successful login immediately clears both the counter and the lockout flag

Configuration:
```dotenv
AUTH_LOCKOUT_ENABLED=true
AUTH_LOCKOUT_MAX=10       # lock after 10 cumulative failures
AUTH_LOCKOUT_DECAY=15     # locked for 15 minutes
```

Locked-out response:
```json
{
  "success": false,
  "message": "Account temporarily locked due to too many failed attempts. Try again in 15 minute(s).",
  "errors": {}
}
```

---

### New Device Detection

When a user successfully logs in from a device (browser + OS combination) that has no prior session in `auth_sessions_extended`, the library:

1. Dispatches `SuspiciousLoginDetected` event
2. The `NotifySuspiciousLogin` listener sends a `NewDeviceLoginNotification` email

The email includes:
- IP address of the new login
- Browser and OS (when available)
- City and country (from ip-api.com geo lookup)

Configuration:
```dotenv
AUTH_NOTIFY_NEW_DEVICE=true
```

To listen to the event yourself (e.g., to also send a push notification):
```php
// In EventServiceProvider
protected $listen = [
    \Joe404\LaravelAuth\Events\SuspiciousLoginDetected::class => [
        \App\Listeners\SendPushNotificationOnNewDevice::class,
    ],
];
```

Event payload:
```php
$event->user        // the authenticated user model
$event->ipAddress   // string
$event->browser     // string|null
$event->os          // string|null
$event->city        // string|null
$event->country     // string|null
```

---

## Real-time Verification (Reverb)

When `AUTH_REVERB_ENABLED=true` and `laravel/reverb` is installed, registration verification triggers a real-time broadcast.

**Setup:**

```dotenv
AUTH_REVERB_ENABLED=true
REVERB_APP_ID=your-reverb-app-id
REVERB_APP_KEY=your-reverb-app-key
REVERB_APP_SECRET=your-reverb-app-secret
```

The `auth:install` command appends a channel auth stub to `routes/channels.php`. You must open the `/broadcasting/auth` route to unauthenticated requests (add it before the `auth:sanctum` middleware group):

```php
// bootstrap/app.php or routes/api.php
Route::post('/broadcasting/auth', function (Request $request) {
    return Broadcast::auth($request);
})->withoutMiddleware('auth:sanctum');
```

**Frontend flow:**

```js
// 1. Register → receive temp_token
const { temp_token } = response.data;

// 2. Subscribe to the private channel
const channel = Echo.private(`auth.verification.${temp_token}`);

// 3. Listen for verification
channel.listen('EmailVerified', (event) => {
    const { token } = event;  // Sanctum token
    // Redirect to app, store token
});
```

**Broadcast payload:**
```json
{
  "verified": true,
  "token": "1|sanctum_token_here",
  "redirect": "/dashboard"
}
```

---

## Device & Session Tracking

Every login creates an `auth_sessions_extended` record. The library detects device info from two sources:

**Web browsers:** Parsed from the `User-Agent` header using `jenssegers/agent`.

**Mobile / API clients:** Send a `X-Device-Info` JSON header:
```json
{
  "model": "SM-G991B",
  "platform": "android",
  "os_version": "14"
}
```

The library maps the `model` code against `resources/devices.json` (~500 entries) to a marketing name. Unknown model codes are stored as-is.

Geo-location (city, country) is fetched from `ip-api.com` using the request IP. Private/local IPs are skipped. The lookup has a 3-second timeout and fails silently.

The session record stored:

| Column | Description |
|---|---|
| `platform` | `web` / `mobile` / `api` |
| `browser` | `Chrome`, `Firefox`, etc. |
| `os` | `Windows`, `iOS`, `Android`, etc. |
| `device_model` | Raw model code |
| `device_marketing_name` | Human name from `devices.json` |
| `ip_address` | Client IP |
| `country` | From geo lookup |
| `city` | From geo lookup |
| `last_active_at` | Updated on every authenticated request |

---

## Admin API Token Management

Admin users (`super-admin` or `admin` role) have access to additional endpoints under `/auth/admin`:

| Method | Endpoint | Description |
|---|---|---|
| `GET` | `/auth/admin/api-tokens` | List all tokens across all users |
| `POST` | `/auth/admin/api-tokens` | Create a token not tied to any user |
| `PATCH` | `/auth/admin/api-tokens/{id}` | Update abilities or expiry |
| `DELETE` | `/auth/admin/api-tokens/{id}` | Revoke any token |

**Admin update request body:**

| Field | Type | Required | Description |
|---|---|---|---|
| `abilities` | array | No | Replaces the token's current abilities |
| `expires_in_days` | integer\|null | No | `null` clears expiry (never expires) |

---

## Role Assignment

The library integrates with `spatie/laravel-permission`. The default role (`AUTH_DEFAULT_ROLE`) is assigned automatically on:
- Email verification (OTP or magic link)
- Google OAuth new user creation

Your user model must use the `HasRoles` trait from Spatie:

```php
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasRoles;
}
```

To protect your own routes by role, use Spatie's built-in middleware:

```php
Route::middleware('role:admin')->group(function () { ... });
Route::middleware('permission:edit-posts')->group(function () { ... });
```

---

## Events Reference

All events are in the `Joe404\LaravelAuth\Events` namespace.

| Event | When fired | Key payload |
|---|---|---|
| `UserRegistered` | After `POST /auth/register` initiates | `$user_email`, `$password` (hashed) |
| `EmailVerified` | After OTP or magic link verification completes | `$user`, `$tempToken`, `$sanctumToken` |
| `UserLoggedIn` | After successful login | `$user`, `$request` |
| `UserLoggedOut` | After logout or logout-all | — |
| `PasswordChanged` | After password reset or change | `$user` |
| `SuspiciousLoginDetected` | Login from unrecognised device | `$user`, `$ipAddress`, `$browser`, `$os`, `$city`, `$country` |

Listen to any event in your `EventServiceProvider`:

```php
protected $listen = [
    \Joe404\LaravelAuth\Events\UserLoggedIn::class => [
        \App\Listeners\LogUserActivity::class,
    ],
    \Joe404\LaravelAuth\Events\PasswordChanged::class => [
        \App\Listeners\NotifyPasswordChange::class,
    ],
];
```

---

## Scheduled Jobs

The library registers two maintenance jobs automatically via `AuthServiceProvider`. They run on the queue named `AUTH_QUEUE_NAME` (default: `auth-maintenance`).

| Job | Schedule | What it does |
|---|---|---|
| `CleanExpiredOtpRecords` | Every 5 minutes | Deletes expired and used rows from `auth_otp_codes` |
| `CleanExpiredApiTokens` | Every hour | Marks or deletes API tokens past their `expires_at` date |

Make sure your queue worker is running:
```bash
php artisan queue:work --queue=auth-maintenance,default
```

Or with Horizon:
```bash
php artisan horizon
```

---

## Environment Variable Quick Reference

```dotenv
# Core
AUTH_MODE=both                          # api | web | both
AUTH_REQUIRE_VERIFICATION=true

# Verification
AUTH_VERIFICATION_METHOD=both           # otp | magic_link | both
AUTH_OTP_LENGTH=6
AUTH_OTP_EXPIRY=10                      # minutes
AUTH_MAGIC_EXPIRY=30                    # minutes

# Tokens
AUTH_TOKEN_EXPIRY=10080                 # minutes (default = 7 days)

# Password
AUTH_PASSWORD_MIN=8
AUTH_PASSWORD_UPPERCASE=false
AUTH_PASSWORD_NUMBER=false
AUTH_PASSWORD_SPECIAL=false
AUTH_PENDING_TTL=60                     # minutes

# Rate Limiting (format: "max:decay_minutes")
AUTH_RATE_REGISTER=5:1
AUTH_RATE_LOGIN=5:1
AUTH_RATE_OTP_SEND=3:1
AUTH_RATE_PASSWORD_RESET=3:1

# Security
AUTH_NOTIFY_NEW_DEVICE=true
AUTH_LOCKOUT_ENABLED=true
AUTH_LOCKOUT_MAX=10
AUTH_LOCKOUT_DECAY=15                   # minutes

# Roles
AUTH_DEFAULT_ROLE=user

# OTP channel
AUTH_OTP_CHANNEL=email                  # email | FQCN

# Google OAuth
AUTH_GOOGLE_ENABLED=false
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
GOOGLE_REDIRECT_URI=

# Reverb
AUTH_REVERB_ENABLED=false

# Queue
AUTH_QUEUE_CONNECTION=redis
AUTH_QUEUE_NAME=auth-maintenance

# Response formatter
AUTH_RESPONSE_FORMATTER=               # FQCN or empty
```

---

## Extending the Library

### Custom user model

The library reads `auth.providers.users.model` from your Laravel config. As long as your user model extends `Illuminate\Foundation\Auth\User`, everything works.

### Custom OTP channel

Implement `Joe404\LaravelAuth\Contracts\OtpChannelContract` and set `AUTH_OTP_CHANNEL` to your FQCN.

### Custom response formatter

Implement `Joe404\LaravelAuth\Contracts\ResponseFormatterContract` and set `AUTH_RESPONSE_FORMATTER` or bind it in your service provider.

### Listening to events

Register listeners in your `EventServiceProvider` as shown in the [Events Reference](#events-reference).

### Adding abilities to API tokens

When creating a token, pass any string as an ability:

```json
{
  "name": "Order service",
  "abilities": ["orders:read", "orders:create", "inventory:read"]
}
```

Protect your routes:
```php
Route::middleware('auth.api-token:orders:read')->get('/orders', ...);
```

Wildcard `["*"]` grants all abilities.
