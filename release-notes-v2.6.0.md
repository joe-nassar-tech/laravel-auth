## v2.6.0 — Phone, 2FA & Trusted Devices

Additive release — existing users without 2FA enrolled see no flow changes.

### Added
- Phone capture + verification (SMS / voice / WhatsApp) via a pluggable driver system: log (dev only), infobip, messagecentral, twilio, firebase, or custom.
- Two-factor authentication: TOTP (authenticator app), email OTP, SMS OTP — enroll multiple, pick any at login. Single-use backup codes.
- Login challenge flow: once 2FA is enrolled, login returns a challenge_token; POST /auth/2fa/challenge issues the real token. Method switching + resend.
- Trusted devices with time-based trust levels (low/medium/high). 2FA bypass requires BOTH the device fingerprint AND a server-issued X-Trusted-Device-Token — fingerprint alone never bypasses.
- Require2FA middleware (auth.2fa) for step-up on sensitive endpoints, with block / force_enroll / password_confirm (sudo) fallbacks.
- Social profile completion: OAuth users missing required fields finish via POST /auth/social/complete (same rules as the email flow).
- Request::authContext(), 8 new events, version-stamped migrations, and php artisan auth:install --upgrade.

### New dependencies
- pragmarx/google2fa ^8.0, bacon/bacon-qr-code ^3.0

### Upgrade
    composer update joe-404/laravel-auth
    php artisan auth:install --upgrade

Then add phone, phone_verified_at, two_factor_required to your User $fillable. Full guide: docs/upgrading.md (v2.6 section).
