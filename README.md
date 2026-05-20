# joe-404/laravel-auth — Complete Laravel Authentication Package

> **⚠ BETA / TESTING NOTICE**
> This package is still under active testing. Bugs may exist. Use with caution in production and [report any issues](https://github.com/joe-nassar-tech/laravel-auth/issues) you encounter.

A **drop-in, config-driven authentication library for Laravel 12 and 13**. One package, one command, and your app has a complete auth system: registration with OTP + magic-link email verification, login, token refresh, password reset, multi-session management, Google OAuth, long-lived API tokens, account status workflow (suspend/ban/deactivate), device fingerprinting, and a referral code system with anti-abuse detection — all through a uniform JSON API, no frontend coupling.

**Works with:** REST APIs · SPA (cookie session) · Mobile apps (Bearer token)

[![Latest Version on Packagist](https://img.shields.io/packagist/v/joe-404/laravel-auth.svg?style=flat-square)](https://packagist.org/packages/joe-404/laravel-auth)
[![PHP](https://img.shields.io/badge/PHP-8.2%2B-blue?style=flat-square)](https://php.net)
[![Laravel](https://img.shields.io/badge/Laravel-12%2B%20%7C%2013%2B-red?style=flat-square)](https://laravel.com)
[![License](https://img.shields.io/badge/license-MIT-green?style=flat-square)](LICENSE)

---

## Table of Contents

- [What Problem Does This Solve?](#what-problem-does-this-solve)
- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Quick Start](#quick-start)
- [API Endpoints](#api-endpoints)
- [Why joe-404/laravel-auth Instead of X?](#why-joe-404laravel-auth-instead-of-x)
- [FAQ](#faq)
- [Documentation](#documentation)
- [Changelog](#changelog)
- [Contributing & Discussions](#contributing--discussions)
- [License](#license)
- [Structured Metadata](#structured-metadata)

---

## What Problem Does This Solve?

Every new Laravel project needs authentication. The typical options are either too coupled to a frontend (Breeze, Jetstream) or too minimal (Fortify, raw Sanctum). Developers end up rebuilding the same things from scratch:

- OTP / magic-link email verification
- Refresh token rotation with reuse detection
- Multi-session tracking with device fingerprinting
- Password reset via OTP **and** signed magic link
- Google OAuth that works alongside password auth
- Long-lived API tokens with expiry and scoping
- Account suspension/ban/deletion with grace period and audit log
- Referral codes with anti-self-abuse fingerprint detection

**joe-404/laravel-auth** solves all of this in a single package. Install it, run `php artisan auth:install`, and every feature above is live — config-driven, fully customizable, and completely decoupled from any frontend.

---

## Features

- **Registration** — email → OTP + magic link → set password → issue token
- **Email verification** — OTP and/or magic link, configurable per environment
- **Login** — password + lockout after N failed attempts, auto-reactivate on login
- **Token system** — Sanctum Bearer tokens + sliding refresh tokens with family-level revocation on reuse
- **SPA session support** — cookie-based auth via `auth:sanctum` guard
- **Password reset** — OTP or signed magic link, configurable
- **Password change** — authenticated endpoint with current-password validation
- **Multi-session management** — list, revoke individual, revoke all
- **Device history** — permanent per-user device log survives logout; browser + mobile fingerprinting
- **Google OAuth** — Socialite integration; links to existing accounts or creates new ones
- **API tokens** — user-scoped and admin-scoped long-lived tokens with optional expiry and ability scoping
- **Account status** — suspend, disable, deactivate (self), soft-delete with 30-day grace and auto-restore
- **Account status audit log** — full history with admin notes
- **Referral codes** — config-driven abuse detection (IP match, device fingerprint match), pluggable reward handler, web + mobile support
- **Role-based access** — Spatie Permission integration (admin gate on all admin routes)
- **Rate limiting** — per-IP and per-email, configurable thresholds and lockout windows
- **Localization** — all messages in `resources/lang/en/` publishable translation files
- **Custom response format** — implement `ResponseFormatterContract` to reshape every JSON response
- **Events** — `UserRegistered`, `EmailVerified`, `UserLoggedIn`, `UserLoggedOut`, `PasswordChanged`, `ReferralCreated`, `ReferralRedeemed`, `SuspiciousReferralDetected`
- **Reverb real-time** — optional WebSocket broadcast for OTP verification

---

## Requirements

| Dependency | Version |
|---|---|
| PHP | `^8.2` |
| Laravel | `^12.0` or `^13.0` |
| Laravel Sanctum | `^4.0` (installed automatically) |
| Laravel Socialite | `^5.0` (installed automatically) |
| Spatie Permission | `^6.0` (installed automatically) |
| Redis | phpredis or predis (recommended for OTP, refresh token, and rate-limit storage) |

---

## Installation

```bash
composer require joe-404/laravel-auth
php artisan auth:install
```

Add the required traits to `app/Models/User.php`:

```php
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;
use Illuminate\Database\Eloquent\SoftDeletes;
use Joe404\LaravelAuth\Concerns\HasAccountStatus;

class User extends Authenticatable
{
    use HasApiTokens, HasRoles, Notifiable, SoftDeletes, HasAccountStatus;
}
```

Set the minimum environment variables:

```env
AUTH_MODE=both
AUTH_VERIFICATION_METHOD=both
MAIL_MAILER=smtp
MAIL_FROM_ADDRESS=hello@yourapp.com
```

> Full install walkthrough, manual steps, and troubleshooting: **[docs/installation.md](docs/installation.md)**

---

## Quick Start

Three-step registration, then login:

```bash
# Step 1 — Submit email, receive OTP + magic link
curl -sX POST http://localhost/auth/register \
  -H "Content-Type: application/json" \
  -d '{"email":"user@example.com"}'
# → { "data": { "temp_token": "uuid", "method": "both", "expires_in": 10 } }

# Step 2 — Verify with OTP code from email
curl -sX POST http://localhost/auth/register/verify-otp \
  -H "Content-Type: application/json" \
  -d '{"email":"user@example.com","otp":"482910"}'
# → { "data": { "completion_token": "uuid" } }

# Step 3 — Set password and create account
curl -sX POST http://localhost/auth/register/complete \
  -H "Content-Type: application/json" \
  -d '{"completion_token":"uuid","password":"Secret123!","password_confirmation":"Secret123!"}'
# → { "data": { "user": {...}, "token": "1|abc...", "refresh_token": "xyz..." } }

# Login
curl -sX POST http://localhost/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"user@example.com","password":"Secret123!"}'
# → { "data": { "user": {...}, "token": "...", "refresh_token": "..." } }
```

Every response uses the same envelope:

```json
{ "success": true,  "message": "...", "data":   {} }
{ "success": false, "message": "...", "errors": {} }
```

---

## API Endpoints

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| `POST` | `/auth/register` | — | Initiate registration (send OTP / magic link) |
| `POST` | `/auth/register/verify-otp` | — | Verify OTP → receive `completion_token` |
| `GET`  | `/auth/register/verify-magic/{token}` | — | Verify magic link → receive `completion_token` |
| `POST` | `/auth/register/complete` | — | Set password and create user |
| `POST` | `/auth/email/resend-verification` | — | Resend OTP / magic link |
| `POST` | `/auth/login` | — | Login (password auth) |
| `POST` | `/auth/logout` | ✓ | Revoke current session |
| `POST` | `/auth/logout/all` | ✓ | Revoke all sessions |
| `GET`  | `/auth/me` | ✓ | Current user + roles |
| `POST` | `/auth/token/refresh` | — | Rotate refresh token |
| `POST` | `/auth/password/forgot` | — | Request password reset |
| `POST` | `/auth/password/reset/verify-otp` | — | Verify reset OTP → `reset_token` |
| `GET`  | `/auth/password/reset/magic/{token}` | — | Verify reset magic link → `reset_token` |
| `POST` | `/auth/password/reset/confirm` | — | Set new password (auto-login) |
| `POST` | `/auth/password/change` | ✓ | Change password (authenticated) |
| `GET`  | `/auth/sessions` | ✓ | List active sessions |
| `DELETE` | `/auth/sessions/{id}` | ✓ | Revoke a session |
| `GET`  | `/auth/devices` | ✓ | List every device that has ever logged in (permanent history) |
| `DELETE` | `/auth/devices/{id}` | ✓ | Forget a device |
| `GET`  | `/auth/social/google/redirect` | — | Google OAuth redirect |
| `GET`  | `/auth/social/google/callback` | — | Google OAuth callback |
| `GET`  | `/auth/social/{provider}/link/confirm/{token}` | — | Confirm social account link |
| `GET`  | `/auth/api-tokens` | ✓ | List your API tokens |
| `POST` | `/auth/api-tokens` | ✓ | Create an API token |
| `DELETE` | `/auth/api-tokens/{id}` | ✓ | Revoke an API token |
| `GET`  | `/auth/admin/api-tokens` | admin | List all API tokens |
| `POST` | `/auth/admin/api-tokens` | admin | Create a system token |
| `PATCH` | `/auth/admin/api-tokens/{id}` | admin | Update a token |
| `DELETE` | `/auth/admin/api-tokens/{id}` | admin | Revoke any token |
| `GET`  | `/auth/admin/users/{id}/status` | admin | Get user status |
| `POST` | `/auth/admin/users/{id}/status` | admin | Change user status (suspend / disable / restore) |
| `GET`  | `/auth/admin/users/{id}/status/history` | admin | Status audit log |
| `POST` | `/auth/admin/users/{id}/notes` | admin | Add admin note |
| `POST` | `/auth/account/deactivate` | ✓ | Self-pause account |
| `DELETE` | `/auth/account` | ✓ | Self-delete account (30-day grace) |
| `POST` | `/auth/referrals/redeem` | ✓ | Submit a referral code after registration |
| `GET`  | `/auth/referrals` | ✓ | List your referrals + status |
| `GET`  | `/auth/referrals/stats` | ✓ | Aggregate counts of your referrals |
| `GET`  | `/auth/admin/referrals` | admin | List all referrals |
| `PATCH` | `/auth/admin/referrals/{id}` | admin | Override referral status |

> Routes are mounted at `/auth` by default. Set `routes.prefix` in `config/auth_system.php` to change (e.g. `api/v1/auth`).

---

## Why joe-404/laravel-auth Instead of X?

| Feature | joe-404/laravel-auth | Laravel Fortify | Laravel Breeze | tymon/jwt-auth |
|---|:---:|:---:|:---:|:---:|
| API-only (no Blade/Inertia) | ✅ | ✅ | ❌ | ✅ |
| OTP email verification | ✅ | ❌ | ❌ | ❌ |
| Magic link verification | ✅ | ❌ | ❌ | ❌ |
| Sliding refresh tokens + reuse detection | ✅ | ❌ | ❌ | ❌ |
| Multi-session management | ✅ | ❌ | ❌ | ❌ |
| Permanent device history | ✅ | ❌ | ❌ | ❌ |
| Google OAuth out of the box | ✅ | ❌ | ✅ (Blade only) | ❌ |
| Long-lived API tokens | ✅ | ❌ | ❌ | ❌ |
| Account suspend / ban / delete workflow | ✅ | ❌ | ❌ | ❌ |
| Referral codes with anti-abuse detection | ✅ | ❌ | ❌ | ❌ |
| Config-driven (zero code required) | ✅ | Partial | ❌ | ❌ |
| SPA cookie + mobile Bearer token | ✅ | Partial | ❌ | ❌ |
| Localization (all messages translatable) | ✅ | ✅ | ✅ | ❌ |
| Custom JSON response format | ✅ | ❌ | ❌ | ❌ |

**vs. Laravel Fortify** — Fortify is headless but provides only the basics (login, register, password reset, two-factor). It has no refresh tokens, no device tracking, no API token management, and no referral system. You still write all the controller logic.

**vs. Laravel Breeze / Jetstream** — These are scaffolding tools that generate Blade or Inertia views. They are not suitable for pure API apps or mobile backends.

**vs. tymon/jwt-auth** — jwt-auth only handles JWT issuance. It has no registration flow, no email verification, no session management, and no account lifecycle features.

---

## FAQ

**Q: Does this work with mobile apps (iOS / Android)?**
Yes. The package supports Bearer token auth (`Authorization: Bearer <token>`) for mobile clients and SPA cookie auth for browser-based apps. All endpoints return JSON with no Blade rendering.

**Q: Can I use this with an existing User model?**
Yes. The package adds traits to your existing model and runs its own migrations alongside yours. Your `users` table is not replaced.

**Q: Does it work with Laravel Octane / Swoole?**
Yes. Services never store `$request` on singleton instances. All request state is passed as method arguments.

**Q: What happens if I disable a feature I don't need?**
Each major feature (referral codes, Google OAuth, API tokens, account deletion) is individually toggle-able via config or `.env`. Disabled features return `404` or are simply not mounted.

**Q: Can I customize the JSON response format?**
Yes. Implement `ResponseFormatterContract` and bind it in your `AppServiceProvider` (or set `auth_system.response.formatter` in config). Every response goes through your formatter.

**Q: How does OTP verification work?**
After `POST /auth/register`, the package sends a 6-digit OTP code to the user's email (and optionally a magic link). The OTP is stored in Redis with a configurable TTL. On `POST /auth/register/verify-otp`, the code is validated and a `completion_token` is returned for the final registration step.

**Q: How are refresh tokens protected against theft?**
Refresh tokens use family-based rotation. Each refresh issues a new token and invalidates the old one. If a stolen token is used after it has already been rotated, the entire token family is revoked — logging out all sessions for that family.

**Q: How does the referral anti-abuse detection work?**
On redemption, the package compares the referrer's device history (stored permanently in `auth_user_devices`) against the new user's IP and browser/device fingerprint. Even if the referrer logs out before the referral is submitted, the historical device record is still checked. The policy (`block` / `flag` / `ignore`) is configurable per signal (same IP, same device, both).

**Q: What PHP and Laravel versions are supported?**
PHP `^8.2` and Laravel `^12.0` or `^13.0`.

**Q: Can I translate error and success messages?**
Yes. Run `php artisan vendor:publish --tag=auth-lang` to publish translation files. See [docs/localization.md](docs/localization.md).

**Q: Is there a rate limiting feature?**
Yes. Login, registration, OTP verification, and password reset endpoints all have configurable rate limits. On lockout, the response includes a `retry_after` field.

---

## Documentation

| Document | What it covers |
|---|---|
| [Installation](docs/installation.md) | Setup, `auth:install`, manual install, troubleshooting |
| [Configuration](docs/configuration.md) | Every config key and `.env` variable |
| [Customization](docs/customization.md) | Extra fields, transformers, OTP channel, response format, email templates, all contracts |
| [Events](docs/events.md) | All lifecycle events, payloads, listeners, queueing |
| [Localization](docs/localization.md) | Multi-language messages, translation files, all message keys |
| [Account Status](docs/account-status.md) | Status workflow, `auth.active` middleware, timed bans, audit log |
| [Account Deletion](docs/account-deletion.md) | Self-delete, grace period, auto-restore, purge worker |
| [Referral Codes](docs/referral-codes.md) | Referral codes, fingerprint anti-abuse, reward handlers, web + mobile integration |
| [Upgrading](docs/upgrading.md) | Version changelog, breaking changes, migration guides |
| [AI Context](docs/AI_Context.md) | Full repo snapshot for AI assistants |

---

## Changelog

See [docs/upgrading.md](docs/upgrading.md) for the full version history, breaking changes, and migration guides between releases.

---

## Contributing & Discussions

- **Bug reports & feature requests:** [GitHub Issues](https://github.com/joe-nassar-tech/laravel-auth/issues)
- **Questions & ideas:** [GitHub Discussions](https://github.com/joe-nassar-tech/laravel-auth/discussions)
- Pull requests are welcome. Please open an issue first for major changes.

---

## License

MIT. See [LICENSE](LICENSE).

---

## Structured Metadata

<details>
<summary>JSON-LD: SoftwareSourceCode schema</summary>

```json
{
  "@context": "https://schema.org",
  "@type": "SoftwareSourceCode",
  "name": "joe-404/laravel-auth",
  "description": "Drop-in, config-driven authentication library for Laravel 12 and 13. Provides registration with OTP and magic-link verification, login, refresh tokens, password reset, multi-session management, device history, Google OAuth, API tokens, account status workflow, and referral codes with anti-abuse fingerprinting.",
  "codeRepository": "https://github.com/joe-nassar-tech/laravel-auth",
  "programmingLanguage": "PHP",
  "runtimePlatform": "Laravel",
  "license": "https://opensource.org/licenses/MIT",
  "author": {
    "@type": "Person",
    "name": "Joe Nassar",
    "url": "https://github.com/joe-nassar-tech"
  },
  "keywords": [
    "laravel authentication",
    "laravel OTP login",
    "laravel magic link",
    "laravel sanctum package",
    "laravel referral codes",
    "laravel API auth",
    "laravel refresh tokens",
    "laravel device tracking",
    "laravel account management",
    "laravel SPA authentication",
    "laravel mobile API",
    "laravel Google OAuth"
  ]
}
```

</details>

<details>
<summary>JSON-LD: FAQ schema</summary>

```json
{
  "@context": "https://schema.org",
  "@type": "FAQPage",
  "mainEntity": [
    {
      "@type": "Question",
      "name": "How do I add authentication to a Laravel API?",
      "acceptedAnswer": {
        "@type": "Answer",
        "text": "Install joe-404/laravel-auth with `composer require joe-404/laravel-auth` then run `php artisan auth:install`. This gives your API registration with email OTP verification, login, token refresh, password reset, and session management — all JSON-based, no frontend coupling."
      }
    },
    {
      "@type": "Question",
      "name": "What is the best Laravel authentication package for mobile apps?",
      "acceptedAnswer": {
        "@type": "Answer",
        "text": "joe-404/laravel-auth supports Bearer token auth for mobile clients alongside SPA cookie auth for browsers. It includes OTP verification, device fingerprinting, and refresh token rotation — features that mobile apps typically need beyond what Sanctum provides out of the box."
      }
    },
    {
      "@type": "Question",
      "name": "How does Laravel OTP email verification work?",
      "acceptedAnswer": {
        "@type": "Answer",
        "text": "After registration, the package sends a 6-digit OTP to the user's email (stored in Redis with a configurable TTL). The user submits the code to POST /auth/register/verify-otp and receives a completion_token used to finalize account creation. A signed magic-link alternative is sent simultaneously if the verification method is set to 'both'."
      }
    },
    {
      "@type": "Question",
      "name": "Does joe-404/laravel-auth support referral codes?",
      "acceptedAnswer": {
        "@type": "Answer",
        "text": "Yes. The referral code system includes anti-self-abuse detection using IP matching and browser/device fingerprinting. The abuse policy (block/flag/ignore) is config-driven. Reward handling is pluggable via ReferralRewardHandlerContract. Works for web, SPA, and mobile clients."
      }
    }
  ]
}
```

</details>

<details>
<summary>Suggested GitHub repository topics</summary>

```
laravel  php  authentication  sanctum  otp  magic-link  oauth  google-oauth
api-authentication  refresh-tokens  session-management  device-fingerprinting
referral-codes  account-management  laravel-package  spa  mobile-api
rate-limiting  email-verification  laravel-auth
```

Set these in your repository **Settings → Topics**.

</details>
