# Account Deletion

Added in v2.4. A self-service soft-delete flow with a configurable grace period during which a normal login transparently restores the account. After the grace window elapses, a scheduled worker permanently anonymises the row.

> **v2.7.3 addition:** the `deleted_accounts.snapshot` column now strips sensitive fields before persistence (the password hash, `remember_token`, and anything else listed in `auth_system.response.hidden_user_fields` or — if set — `auth_system.account.deletion.snapshot_strip_fields`). Closes a privacy gap where a host User model without `$hidden` could otherwise retain credential material in the permanent deletion audit row.

---

## Table of Contents

1. [Flow overview](#1-flow-overview)
2. [Configuration](#2-configuration)
3. [Self-service endpoint](#3-self-service-endpoint)
4. [The deleted_accounts table](#4-the-deleted_accounts-table)
5. [Auto-restore on login](#5-auto-restore-on-login)
6. [The purge worker](#6-the-purge-worker)
7. [Unique column handling](#7-unique-column-handling)
8. [Events fired](#8-events-fired)
9. [Required model traits](#9-required-model-traits)
10. [Customising the notification emails](#10-customising-the-notification-emails)
11. [Disabling the feature](#11-disabling-the-feature)

---

## 1. Flow overview

```
DELETE /auth/account  (user hits endpoint)
          │
          ▼
┌──────────────────────────────────────────────────────┐
│  Snapshot full users row → deleted_accounts table    │
│  Set account_status = "deleted"                      │
│  Set deleted_at = now()                              │
│  Set scheduled_purge_at = now() + grace_days         │
│  Revoke all Sanctum tokens + session rows            │
│  Fire AccountDeleted event                           │
│  Send AccountDeletedNotification                     │
└──────────────────────────────────────────────────────┘
          │
          │ ◄── User logs in again during grace period?
          │
     YES  │  NO ─── grace expires
          ▼                  ▼
┌──────────────────┐  ┌──────────────────────────────────────────────┐
│  Set status=active│  │  PurgeExpiredAccountDeletions (hourly)       │
│  Clear deleted_at │  │    Null unique columns on users row          │
│  Drop deleted_    │  │    (hard-delete users row if configured)     │
│  accounts row     │  │    Set deleted_accounts.purged_at = now()   │
│  Fire             │  │    Fire AccountPurged event                  │
│  AccountRestored  │  └──────────────────────────────────────────────┘
│  Normal login     │
└──────────────────┘
```

---

## 2. Configuration

```php
// config/auth_system.php
'account' => [
    'deletion' => [
        'enabled'                  => true,
        'self_service'             => true,
        'require_password'         => true,
        'grace_days'               => 30,
        'auto_restore_on_login'    => true,
        'null_uniques_after_grace' => true,
        'hard_delete_after_grace'  => false,
        'move_to_deleted_table'    => true,
        'unique_columns'           => 'auto',
        'unique_exclude'           => ['id'],
    ],
],
```

| Key | Default | Description |
|---|---|---|
| `enabled` | `true` | Master switch. `false` = feature is off entirely, endpoint returns 403. |
| `self_service` | `true` | `true` = expose `DELETE /auth/account`. `false` = only admins can trigger deletion via the status endpoint. |
| `require_password` | `true` | Require the user's current password on the delete call. Strongly recommended. |
| `grace_days` | `30` | Days the account stays restorable. Login within this window auto-restores. |
| `auto_restore_on_login` | `true` | Silently restore on a credential match within grace. Recommended. |
| `null_uniques_after_grace` | `true` | After grace, null unique columns (email, username) so they can be reclaimed by a new signup. |
| `hard_delete_after_grace` | `false` | After grace, hard-delete the `users` row. The `deleted_accounts` audit row is kept regardless. |
| `move_to_deleted_table` | `true` | Snapshot the full `users` row into `deleted_accounts` at delete time. Disable only if you have your own audit mechanism. |
| `unique_columns` | `'auto'` | `'auto'` = introspect schema. Or pass an explicit array: `['email', 'username']`. |
| `unique_exclude` | `['id']` | Columns the resolver must never null (primary keys, etc.). |

**Environment variables:**

```env
AUTH_ACCOUNT_DELETE_ENABLED=true
AUTH_ACCOUNT_DELETE_SELF=true
AUTH_ACCOUNT_DELETE_REQUIRE_PASSWORD=true
AUTH_ACCOUNT_DELETE_GRACE_DAYS=30
AUTH_ACCOUNT_AUTO_RESTORE=true
AUTH_ACCOUNT_NULL_UNIQUES=true
AUTH_ACCOUNT_HARD_DELETE=false
AUTH_ACCOUNT_AUDIT_TABLE=true
AUTH_ACCOUNT_UNIQUE_COLUMNS=auto
```

---

## 3. Self-service endpoint

```
DELETE /auth/account
Authorization: Bearer <token>
Content-Type: application/json

{
  "password": "current-password",
  "reason": "optional free text explaining why"
}
```

**Response (200):**

```json
{
  "success": true,
  "message": "Account scheduled for deletion.",
  "data": {
    "scheduled_purge_at": "2026-06-15T10:34:21+00:00",
    "grace_days": 30,
    "auto_restore": true
  }
}
```

**Error responses:**

| HTTP | Reason |
|---|---|
| `403` | `self_service=false` or feature disabled |
| `422` | Missing or wrong password (when `require_password=true`) |

---

## 4. The `deleted_accounts` table

Created by the package migration. Stores a permanent audit snapshot of every deleted account.

| Column | Type | Notes |
|---|---|---|
| `id` | `bigint PK` | |
| `original_user_id` | `bigint` | Indexed. **No foreign key** — the users row may be hard-deleted. |
| `email` | `varchar`, nullable | Original email for audit queries. |
| `username` | `varchar`, nullable | Original username, if applicable. |
| `delete_reason` | `text`, nullable | User-supplied reason from the delete call. |
| `snapshot` | `json` | Full users row at delete time. |
| `deleted_at` | `timestamp` | When the user hit delete. |
| `scheduled_purge_at` | `timestamp` | Indexed — when the purge worker picks this row up. |
| `purged_at` | `timestamp`, nullable | Set by the purge worker after anonymisation. |
| `timestamps` | | Standard `created_at` / `updated_at`. |

**Why no foreign key back to `users`?**

When `hard_delete_after_grace=true`, the `users` row disappears permanently. A foreign key would prevent the `deleted_accounts` row from surviving. Since app tables (orders, transactions, audit events) may reference `original_user_id`, the audit row must outlive the users row. Without an FK, those references remain readable from the snapshot.

---

## 5. Auto-restore on login

If `auto_restore_on_login=true` (default), a successful credential match on a `status=deleted` user within the grace period triggers a transparent restore:

1. Package detects `status=deleted` and `scheduled_purge_at > now()`
2. Calls `AccountDeletionService::restore($user)`
3. Clears `deleted_at` (via Eloquent `restore()` — requires `SoftDeletes` trait on `User`)
4. Flips `account_status` back to `active`
5. Drops the `deleted_accounts` row
6. Fires `AccountRestored` event
7. Sends `AccountRestoredNotification`
8. Continues issuing the token/session normally

The user sees a normal successful login response. No extra round-trip, no "restore" endpoint.

---

## 6. The purge worker

`PurgeExpiredAccountDeletions` runs **hourly** on the `auth-maintenance` queue. It chunks `deleted_accounts` rows where `scheduled_purge_at <= now()` and `purged_at IS NULL`, and calls `AccountDeletionService::purge()` on each.

**Per-row purge does (in order):**

1. Null the unique columns on the `users` row (email, username, etc.) so they can be reclaimed
2. Hard-delete the `users` row if `hard_delete_after_grace=true`
3. Set `deleted_accounts.purged_at = now()`
4. Fire `AccountPurged` event

Per-row failures are caught and logged — a single failure does not block the rest of the batch.

**Make sure your queue worker is running:**

```bash
php artisan queue:work --queue=auth-maintenance
```

---

## 7. Unique column handling

After grace, the purge worker nulls unique columns on the `users` row so that a new signup with the same email or username can succeed.

**Auto-discovery (default):**

`UniqueColumnResolver` calls `Schema::getIndexes('users')` and picks every single-column unique index that is not the primary key. It caches the result for the request lifetime.

**Force an explicit list:**

```php
'unique_columns' => ['email', 'username'],
```

**Exclude specific columns from auto-discovery:**

```php
'unique_columns' => 'auto',
'unique_exclude' => ['id', 'external_uuid'],
```

**Required:** your `users` table unique columns must allow `NULL`, otherwise the purge fails:

```php
// Your users migration must have:
$table->string('email')->nullable()->unique();
$table->string('username')->nullable()->unique();
```

If a column is `NOT NULL` with a `DEFAULT`, the purge will throw a DB error. Fix the migration and re-run before enabling the feature.

---

## 8. Events fired

| Event | Fired when | Payload |
|---|---|---|
| `AccountDeleted` | User calls `DELETE /auth/account` | `$user`, `$gracePeriodDays`, `$scheduledPurgeAt` |
| `AccountRestored` | Login auto-restore during grace | `$user` |
| `AccountPurged` | Purge worker permanently anonymises the row | `$userId`, `$email` (from snapshot) |

Listen with the standard auto-discovery pattern:

```php
// app/Listeners/HandleAccountDeletion.php
use Joe404\LaravelAuth\Events\AccountDeleted;

class HandleAccountDeletion
{
    public function handle(AccountDeleted $event): void
    {
        // e.g. cancel subscriptions, freeze wallet, notify billing
        $event->user->wallet->freeze();
    }
}
```

```php
// app/Listeners/HandleAccountPurged.php
use Joe404\LaravelAuth\Events\AccountPurged;

class HandleAccountPurged
{
    public function handle(AccountPurged $event): void
    {
        // $event->userId — the original ID (users row may be gone)
        // $event->email  — from the deleted_accounts snapshot
        ExternalAnalytics::trackAccountDeleted($event->userId);
    }
}
```

---

## 9. Required model traits

```php
use Illuminate\Database\Eloquent\SoftDeletes;
use Joe404\LaravelAuth\Concerns\HasAccountStatus;

class User extends Authenticatable
{
    use HasApiTokens, HasRoles, Notifiable, SoftDeletes, HasAccountStatus;
}
```

`SoftDeletes` is required — the auto-restore flow calls `$user->restore()` which is provided by this trait. Without it, the restore throws a `BadMethodCallException`.

`HasAccountStatus` is optional sugar. The package reads and writes the status column directly either way.

---

## 10. Customising the notification emails

Same pattern as OTP/magic-link emails. Each lifecycle email can be overridden in config:

```php
'mail' => [
    'account_deleted_notification'  => \App\Notifications\MyDeleteEmail::class,
    'account_restored_notification' => \App\Notifications\MyRestoreEmail::class,
    'account_purged_notification'   => null,  // null = use built-in (default)
    'account_notifications_enabled' => [
        'deleted'  => true,
        'restored' => true,
        'purged'   => false,  // off by default — background worker action
    ],
],
```

Or publish and edit the Blade view:

```bash
php artisan vendor:publish --tag=auth-views
```

Edit files in `resources/views/vendor/laravel-auth/emails/`.

---

## 11. Disabling the feature

```php
'account' => [
    'deletion' => [
        'enabled' => false,
    ],
],
```

Or via `.env`:

```env
AUTH_ACCOUNT_DELETE_ENABLED=false
```

`DELETE /auth/account` returns HTTP 403. The migration columns and `deleted_accounts` table remain — disabling is a runtime decision, not a schema teardown.
