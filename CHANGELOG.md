# Changelog

All notable changes to `joe-404/laravel-auth` are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/) and this project uses [Semantic Versioning](https://semver.org/).

---

## [1.0.0] — 2025-05-08

### Added

**Core authentication (M1)**
- `POST /auth/register` — initiates registration, stores credentials in cache, sends OTP + magic link simultaneously, returns `temp_token`
- `POST /auth/register/verify-otp` — completes registration via numeric OTP code
- `GET /auth/register/verify-magic/{token}` — completes registration via signed magic link
- `POST /auth/email/resend-verification` — resends OTP/magic link without revealing whether the email exists
- `POST /auth/login` — authenticates verified users, issues Sanctum Bearer token or session cookie depending on `AUTH_MODE`
- `POST /auth/logout` — revokes current token/session
- `POST /auth/logout/all` — revokes all sessions across all devices
- `GET /auth/me` — returns user profile, roles, permissions, and active session count

**Password management (M4)**
- `POST /auth/password/forgot` — sends OTP + magic link for password reset (email-enumeration-safe)
- `POST /auth/password/reset/otp` — resets password by submitting OTP + new password in one call
- `GET /auth/password/reset/magic/{token}` — validates signed reset URL, returns short-lived `reset_token`
- `POST /auth/password/reset/confirm` — sets new password using `reset_token`; revokes all existing sessions
- `POST /auth/password/change` — changes password for authenticated user; optional `logout_all` flag

**Session & device tracking (M2)**
- `GET /auth/sessions` — lists all active sessions with device, browser, OS, IP, and geo info
- `DELETE /auth/sessions/{id}` — revokes a specific session by ID
- Device detection from `User-Agent` (web) and `X-Device-Info` header (mobile)
- ~500-model device lookup via `resources/devices.json`
- Geo-location from `ip-api.com` (city + country, fails silently)

**API token system (M3)**
- `GET /auth/api-tokens` — lists all API tokens for the authenticated user
- `POST /auth/api-tokens` — creates a scoped, optionally expiring API token (`auth_at_` prefix)
- `DELETE /auth/api-tokens/{id}` — revokes a user-owned token
- `GET /auth/admin/api-tokens` — admin: lists all tokens across all users
- `POST /auth/admin/api-tokens` — admin: creates a token not tied to any user
- `PATCH /auth/admin/api-tokens/{id}` — admin: updates abilities or expiry
- `DELETE /auth/admin/api-tokens/{id}` — admin: revokes any token
- `ApiTokenAuth` middleware with per-ability checks (`auth.api-token:read,orders`)

**Google OAuth (M5)**
- `GET /auth/social/google/redirect` — returns Google authorization URL
- `GET /auth/social/google/callback` — handles OAuth exchange; creates, links, or logs in user automatically

**Real-time verification via Reverb (M6)**
- Broadcasts `EmailVerified` event on `auth.verification.{temp_token}` private channel when enabled
- Frontend receives Sanctum token in real time without polling
- `auth:install` appends Reverb channel stub to host app's `routes/channels.php`

**Security hardening (M7)**
- Dual-layer rate limiting: per-IP and per-email independently, on all public endpoints
- Account lockout: cumulative failure tracking across rate-limit windows (`AUTH_LOCKOUT_MAX`, `AUTH_LOCKOUT_DECAY`)
- New-device detection: `SuspiciousLoginDetected` event + `NewDeviceLoginNotification` email
- `RequireEmailVerified` middleware
- `DeviceFingerprint` middleware

**Infrastructure**
- `AuthServiceProvider` with auto-discovery (zero manual registration required)
- `php artisan auth:install` — publishes config, migrations, seeder, channel stub
- `AuthRolesSeeder` — creates `super-admin`, `admin`, `user` roles via Spatie Permission
- `CleanExpiredOtpRecords` job (every 5 min) and `CleanExpiredApiTokens` job (every hour)
- Customisable response envelope via `ResponseFormatterContract`
- Customisable OTP delivery via `OtpChannelContract` (swap email for SMS, WhatsApp, etc.)
- Full Pest test suite: unit + feature tests with `Mail::fake()` and `Queue::fake()`
- `declare(strict_types=1)` and return types on every method; Octane singleton-safe services
