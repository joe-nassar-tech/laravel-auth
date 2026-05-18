# Account Status

Added in v2.4. A configurable status workflow for every user. Changing a status takes effect immediately — no waiting for tokens to expire.

---

## Table of Contents

1. [The five statuses](#1-the-five-statuses)
2. [Schema columns added](#2-schema-columns-added)
3. [Login enforcement](#3-login-enforcement)
4. [Per-request enforcement — auth.active middleware](#4-per-request-enforcement--authactive-middleware)
5. [Changing status from code](#5-changing-status-from-code)
6. [Admin endpoints](#6-admin-endpoints)
7. [Timed bans (auto-unban)](#7-timed-bans-auto-unban)
8. [deactivated — user self-pause](#8-deactivated--user-self-pause)
9. [disabled — admin violation ban](#9-disabled--admin-violation-ban)
10. [Custom statuses](#10-custom-statuses)
11. [Audit log](#11-audit-log)
12. [Disabling the feature](#12-disabling-the-feature)
13. [Cheat sheet](#13-cheat-sheet)

---

## 1. The five statuses

| Status | Who sets it | Behaviour |
|---|---|---|
| `active` | Default | Normal user. Can log in. |
| `suspended` | Admin | Temporary ban. Can carry an `expires_at` for auto-unban. Default timed-capable status. |
| `disabled` | Admin | **Meta-style violation ban.** Permanent; requires manual admin reactivation. No expiry by default. |
| `deactivated` | User (self) | Instagram-style pause. Auto-reactivates the instant the user logs in again. No deadline. |
| `deleted` | User (self) | Soft-deleted with 30-day grace. Auto-restores on login within grace, permanently anonymised after. |

The list lives in config so you can add custom statuses (e.g. `pending_review`) without forking the package.

---

## 2. Schema columns added

`php artisan auth:install` (or `php artisan migrate`) adds these columns to your `users` table:

| Column | Type | Purpose |
|---|---|---|
| `account_status` | `varchar(32)` | Current status. Defaults to `active`. |
| `status_changed_at` | `timestamp` | When the status last changed. |
| `status_reason` | `text` | Optional admin/user-supplied reason. |
| `status_expires_at` | `timestamp` | When a timed ban automatically lifts. `null` = permanent. |
| `deleted_at` | `timestamp` | SoftDeletes column (for the deletion grace flow). |

**Required User model traits:**

```php
use Illuminate\Database\Eloquent\SoftDeletes;
use Joe404\LaravelAuth\Concerns\HasAccountStatus;

class User extends Authenticatable
{
    use HasApiTokens, HasRoles, Notifiable, SoftDeletes, HasAccountStatus;
}
```

`SoftDeletes` is required for account deletion auto-restore. `HasAccountStatus` is optional — it adds convenience methods (`$user->isActive()`, `$user->isSuspended()`, `$user->isBanned()`, etc.).

---

## 3. Login enforcement

`AuthService::login()` calls `AccountStatusService::assertCanLogin($user)` after a successful credential check. If the user's status is in `login_blocked`, login is rejected with an HTTP 403 and a per-status translatable error message.

**Default `login_blocked` statuses:**

| Status | Error key | Default English message |
|---|---|---|
| `disabled` | `account_disabled` | This account has been disabled. |
| `suspended` | `account_suspended` | This account has been suspended. |

The `deleted` status is **not** in `login_blocked` — the login flow detects it and auto-restores the account if within the grace period. See [account-deletion.md](account-deletion.md).

The `deactivated` status is also not in `login_blocked` — it is listed in `login_auto_restorable`, so a successful login silently flips the user back to `active`.

**Override messages:**

```php
// config/auth_system.php
'errors' => [
    'account_disabled'  => 'Your account was disabled. Contact support to appeal.',
    'account_suspended' => 'Your account has been temporarily suspended.',
],
```

Or via translation files: `lang/vendor/auth_system/<locale>/errors.php`.

---

## 4. Per-request enforcement — auth.active middleware

Without additional middleware, a status change only blocks the **next login** — existing tokens continue working until they expire or are revoked.

Apply the `auth.active` middleware to any route group that should immediately reject users whose status changed after they logged in:

```php
// routes/api.php
Route::middleware(['auth:sanctum', 'auth.active'])->group(function (): void {
    Route::get('/feed', FeedController::class);
    Route::post('/posts', PostController::class);
    // ...
});
```

With `auth.active`, the very next request from a suspended/disabled user returns HTTP 403 — even if their token has not expired.

**How it works:** the middleware calls `AccountStatusService::current($user)`, which runs the lazy auto-unban check first, then returns the current status. If the status is in `login_blocked`, a 403 is returned.

The middleware is registered automatically by the package service provider under the alias `auth.active`.

---

## 5. Changing status from code

```php
use Joe404\LaravelAuth\Services\AccountStatusService;

$service = app(AccountStatusService::class);

// Suspend permanently
$service->changeStatus($user, 'suspended', 'Spam reports above threshold.');

// Suspend for 24 hours
$service->changeStatus($user, 'suspended', 'Cooling-off period.', [
    'expires_at' => now()->addHours(24),
]);

// Disable permanently
$service->changeStatus($user, 'disabled', 'Policy violation — third strike.');

// Restore
$service->changeStatus($user, 'active', 'Appeal approved.');
```

**Side effects of `changeStatus()`:**

1. Writes `account_status`, `status_changed_at`, `status_reason`, `status_expires_at` to the users row
2. If `revoke_sessions_on_change=true` and status is no longer `active`, revokes all Sanctum tokens and `AuthSessionExtended` rows
3. Fires `AccountStatusChanged` event
4. Sends `AccountStatusChangedNotification` if `mail.account_notifications_enabled.status_changed=true` (off by default)
5. Writes an audit row to `account_status_logs` if the audit feature is enabled

---

## 6. Admin endpoints

All require the role/permission configured in `account.status.admin_ability` (default: `super-admin` or `admin`).

### Get current status

```
GET /auth/admin/users/{id}/status
```

**Response:**

```json
{
  "success": true,
  "data": {
    "user_id": 42,
    "status": "suspended",
    "status_expires_at": "2026-07-17T12:00:00+00:00",
    "status_changed_at": "2026-05-17T10:00:00.000000Z",
    "status_reason": "Cooling off period.",
    "allowed": ["active", "disabled", "suspended", "deleted", "deactivated"]
  }
}
```

### Change status

```
POST /auth/admin/users/{id}/status
```

**Request body:**

```json
{
  "status": "suspended",
  "reason": "Short reason tag — shown in UI",
  "comment": "Optional long admin note attached to this change in the audit log.",
  "expires_at": "2026-07-17T12:00:00Z",
  "duration_minutes": 120
}
```

| Field | Required | Description |
|---|---|---|
| `status` | Yes | The new status. Must be in `account.status.allowed`. |
| `reason` | No | Short human-readable reason (e.g. `"spam"`, `"policy_violation"`). |
| `comment` | No | Long free-form admin note stored in the audit log. |
| `expires_at` | No | ISO 8601 datetime when the ban auto-lifts. Requires the status to be in `auto_unban.temporary_statuses`. |
| `duration_minutes` | No | Alternative to `expires_at`. If both are sent, `expires_at` wins. |

Passing `expires_at` or `duration_minutes` for a status not in `temporary_statuses` returns HTTP 422.

---

## 7. Timed bans (auto-unban)

When an admin sets `expires_at` or `duration_minutes`, the system flips the user back to `active` automatically when the expiry elapses.

**Two complementary mechanisms:**

### Lazy revert

Every status read — login, `auth.active` middleware, `GET /me`, `GET /auth/admin/users/{id}/status` — passes through `AccountStatusService::current($user)`. If `status_expires_at <= now()` and the user is not already `active`, the package flips them on the spot before returning. This means a user can log in the **instant** their ban expires — no waiting for the sweep worker.

### Scheduled sweep

Every `auto_unban.sweep_minutes` minutes (default: 5), the `RevertExpiredAccountStatuses` job sweeps every row with an elapsed `status_expires_at` that the lazy path hasn't touched yet and reverts them. Both paths call `changeStatus()`, so `AccountStatusChanged` fires exactly once per revert.

**Configuration:**

```php
'account' => [
    'status' => [
        'auto_unban' => [
            'enabled'            => true,
            'sweep_minutes'      => 5,
            'temporary_statuses' => ['suspended'],  // add 'disabled' if you want it timed too
        ],
    ],
],
```

```env
AUTH_ACCOUNT_AUTO_UNBAN=true
AUTH_ACCOUNT_AUTO_UNBAN_SWEEP=5
```

**Null expiry on a timed-capable status is still a permanent ban.** The worker only acts when `status_expires_at` is set. Omitting an expiry means the ban lasts forever.

**Manually clearing a ban:**

Calling `changeStatus($user, 'active', ...)` always wipes `status_expires_at`, regardless of whether a duration was originally set.

---

## 8. `deactivated` — user self-pause

Instagram-style account pause. The user can come back at any time by logging in — no deadline, nothing is deleted.

### Endpoint

```
POST /auth/account/deactivate
Authorization: Bearer <token>
Content-Type: application/json

{
  "password": "current-password",
  "reason": "optional free text"
}
```

### What it does

1. Writes `account_status = deactivated`, `status_changed_at`, optional `status_reason`
2. Revokes all Sanctum tokens and `AuthSessionExtended` rows (user is signed out everywhere)
3. Fires `AccountStatusChanged` event
4. Sends `AccountDeactivatedNotification` if `mail.account_notifications_enabled.deactivated=true` (default: on)

### What it does NOT do

- Does not soft-delete the user row
- Does not schedule a purge
- Does not touch unique columns (email, username remain unchanged)

### Auto-reactivation on login

When a `deactivated` user logs in with the correct credentials:

1. Package detects `status=deactivated` and `deactivated` is in `login_auto_restorable`
2. Calls `changeStatus($user, 'active', 'auto_reactivate')`
3. Fires `AccountStatusChanged` event
4. Sends `AccountReactivatedNotification`
5. Continues issuing the token/session normally

The user sees a normal successful login response — no separate "reactivate" endpoint, no extra round-trip.

### Configuration

```php
'account' => [
    'deactivation' => [
        'enabled'                  => true,
        'self_service'             => true,
        'require_password'         => true,
        'auto_reactivate_on_login' => true,
    ],
],
```

**To require a support ticket to come back:** set `auto_reactivate_on_login=false` and add `deactivated` to `account.status.login_blocked`.

---

## 9. `disabled` — admin violation ban

`disabled` is the heaviest status — a Meta/Facebook-style ban for policy violations. The user cannot log in and the status only reverts via deliberate admin action.

- **Always permanent by default** — not in `temporary_statuses`, so passing `expires_at` returns HTTP 422. Add `'disabled'` to `temporary_statuses` if you want it to support expiry.
- Login is rejected with the `account_disabled` error key.
- The reason is captured in `status_reason` for the admin's records.
- The user has no self-service way out. The intended flow is an **appeal workflow**: the user submits an appeal, an admin reviews it, and on approval calls `POST /auth/admin/users/{id}/status` with `status=active`.

---

## 10. Custom statuses

Add a string to `allowed`. If it should block login, also add it to `login_blocked`. Then add the matching error key.

**Config:**

```php
'account' => [
    'status' => [
        'allowed'       => ['active', 'disabled', 'suspended', 'deleted', 'deactivated', 'pending_review'],
        'login_blocked' => ['disabled', 'suspended', 'pending_review'],
    ],
],
```

**Translation (error key is always `account_{status}`):**

```php
// lang/vendor/auth_system/en/errors.php (host-published copy)
'account_pending_review' => 'Your account is pending review. We will email you within 24 hours.',
```

**Timed-capable custom status:**

```php
'auto_unban' => [
    'temporary_statuses' => ['suspended', 'pending_review'],
],
```

---

## 11. Audit log

Every status transition — admin, user, or automatic — is written to `account_status_logs`. A second admin can open a user's history and understand the chain of events without pinging anyone.

### What each audit row stores

| Column | Description |
|---|---|
| `actor_type` | `admin`, `user`, or `system` |
| `actor_id` | ID of the admin or user who acted. `null` for system. |
| `action` | `status_change` or `note` |
| `from_status` | Status before the change |
| `to_status` | Status after the change |
| `reason` | Short tag (e.g. `spam`, `appeal_approved`) |
| `comment` | Long free-form admin note |
| `source` | Context tag — see source tags below |
| `expires_at` | Expiry set on the new status |
| `ip_address` | IP when an HTTP request is in scope |
| `user_agent` | User agent string when in scope |

### Source tags

| Source | Fires when |
|---|---|
| `admin_endpoint` | Admin hits `POST /auth/admin/users/{id}/status` |
| `admin_note` | Admin hits `POST /auth/admin/users/{id}/notes` |
| `self_deactivate` | User hits `POST /auth/account/deactivate` |
| `self_delete` | User hits `DELETE /auth/account` |
| `login_auto_restore` | Login auto-restore for a `deleted` user inside grace |
| `login_auto_reactivate` | Login auto-reactivate for a `deactivated` user |
| `auto_unban_lazy` | Lazy revert inside `AccountStatusService::current()` |
| `auto_unban_sweep` | Sweep worker reverts an expired ban |
| `purge_worker` | Purge worker permanently anonymises a deleted row |

Pass any custom string: `$service->changeStatus($user, 'active', 'manual', ['source' => 'support_ticket_#4711'])`.

### Audit endpoints

Both are gated by `account.status.admin_ability`.

**Status history:**

```
GET /auth/admin/users/{id}/status/history
GET /auth/admin/users/{id}/status/history?actor_type=admin&action=status_change&from=2026-01-01&to=2026-06-01&page=2&per_page=50
```

**Add admin note (without changing status):**

```
POST /auth/admin/users/{id}/notes

{
  "comment": "User emailed support twice — waiting on product reply.",
  "reason": "awaiting_review"
}
```

### Configuration

```php
'account' => [
    'audit' => [
        'enabled'              => true,     // false = nothing logged, endpoints return 404
        'table'                => 'account_status_logs',
        'log_status_changes'   => true,     // false = only notes logged
        'log_system_actions'   => true,     // false = only human-initiated actions
        'capture_request_meta' => true,     // false = no IP/UA stored
        'retention_days'       => null,     // null = keep forever; N = daily cleanup
        'notes'   => ['enabled' => true],
        'history' => ['enabled' => true, 'default_per_page' => 20, 'max_per_page' => 100],
    ],
],
```

All audit writes are wrapped in try/catch — a logger failure never blocks the underlying action.

---

## 12. Disabling the feature

Set `account.status.enabled = false`. Login and the `auth.active` middleware skip all status checks. The columns remain in the schema — disabling is a runtime decision, not a teardown.

```php
'account' => [
    'status' => [
        'enabled' => false,
    ],
],
```

Or via `.env`:

```env
AUTH_ACCOUNT_STATUS_ENABLED=false
```

---

## 13. Cheat sheet

| Goal | What to do |
|---|---|
| Cooling-off ban that lifts itself | `suspended` + `duration_minutes` or `expires_at` |
| Policy violation — requires human review to lift | `disabled` (permanent, no expiry) |
| User wants a break but can come back | `deactivated` (auto-restores on next login) |
| User wants to leave but might change their mind | `deleted` (30-day grace, auto-restores on login during grace) |
| Immediate effect after status change | Add `auth.active` middleware to your route groups |
| Custom status that blocks login | Add to `allowed` + `login_blocked` + add error key |
| Temporary custom ban | Add to `allowed` + `login_blocked` + `auto_unban.temporary_statuses` |
