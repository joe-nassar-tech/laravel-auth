# Upgrading Guide

---

## Upgrading to 1.x from pre-release

### Registration flow changed — breaking

The registration flow now requires **three steps** instead of two. Passwords are no longer accepted in `POST /auth/register` and are no longer cached. This eliminates the pre-account takeover attack vector.

**Old flow (pre-release):**
1. `POST /auth/register` with `{ email, password }` → sends OTP/magic link
2. Verify email → user created with cached password

**New flow (1.x):**
1. `POST /auth/register` with `{ email }` only → sends OTP/magic link
2. Verify email → receive `completion_token`
3. `POST /auth/register/complete` with `{ completion_token, password }` → user created

**Frontend changes required:**
- Remove `password` and `password_confirmation` from the initial registration request body
- Store the `completion_token` from the verify response
- Add a "Set your password" step that POSTs to `POST /auth/register/complete`

**Backend migration:** Run `php artisan migrate` to add the new `failed_attempts` column to `auth_otp_codes`.

---

### Refresh tokens moved to dedicated table — breaking

Refresh tokens are now stored in `auth_refresh_tokens`, not in `personal_access_tokens`. This means existing refresh tokens from pre-release installations are no longer valid.

**Action required:**
1. Run `php artisan migrate` to create the `auth_refresh_tokens` table
2. Existing logged-in users will need to re-authenticate to receive new refresh tokens

---

### OTP codes and magic links now stored as hashes

`auth_otp_codes.token` now stores SHA-256 hashes instead of plaintext. The `token` column type is set to `string(64)` in the migration.

**Action required:** If you have any existing OTP records in a development database, clear them — they will no longer match any hash lookups.

---

### `completeRegistrationWithOtp` / `completeRegistrationWithMagicLink` return changed

Both methods now return `['completion_token' => 'uuid']` instead of `['user' => ..., 'token' => ...]`.

If you called these methods directly (bypassing the controllers), update callers to use the new `finalizeRegistration(string $completionToken, string $password, Request $request)` method.

---

### `EmailVerified` event — `sanctumToken` parameter removed

The event constructor no longer accepts a Sanctum token. If you listened to `EmailVerified` and used `$event->sanctumToken`, remove that usage.

```php
// Before
class EmailVerified {
    public function __construct(User $user, string $tempToken, ?string $sanctumToken = null) {}
}

// After
class EmailVerified {
    public function __construct(User $user, string $tempToken) {}
}
```

---

### `SocialAuthService::redirectUrl` signature changed

The method now requires a `Request` parameter (used to determine stateful vs. stateless OAuth):

```php
// Before
public function redirectUrl(string $provider): string

// After
public function redirectUrl(string $provider, Request $request): string
```

Update any direct calls to pass the current request.

---

### `handleCallback` return type changed

`SocialAuthService::handleCallback` now returns an array with a `status` key (`'logged_in'` or `'requires_link_confirmation'`). If you called this method directly, check for the status key before reading `user` / `token`.
