# Account Deletion

v2.4 adds a soft-delete flow with a configurable grace period during which a
normal login transparently restores the account. After the grace window
elapses a scheduled worker permanently anonymises the row.

## Flow at a glance

```
DELETE /auth/account
        │
        ▼
┌────────────────────────────────────────────┐
│  Snapshot full users row → deleted_accounts│
│  status = "deleted"                        │
│  deleted_at = now()                        │
│  scheduled_purge_at = now() + grace_days   │
│  revoke all tokens + sessions              │
│  send AccountDeletedNotification           │
└────────────────────────────────────────────┘
        │
        │   User logs in again within grace?
        ├──────── YES ────────┐
        │                     ▼
        │            ┌──────────────────────────┐
        │            │  status = "active"       │
        │            │  deleted_at = null       │
        │            │  drop deleted_accounts   │
        │            │  send AccountRestored    │
        │            │  continue normal login   │
        │            └──────────────────────────┘
        │
        ▼ NO — grace expires
┌────────────────────────────────────────────┐
│  PurgeExpiredAccountDeletions (hourly)    │
│    null all unique columns on users row    │
│    optionally hard-delete users row        │
│    set deleted_accounts.purged_at = now()  │
│    fire AccountPurged                      │
└────────────────────────────────────────────┘
```

## Configuration

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

| Key                       | Effect                                                                                          |
|---------------------------|-------------------------------------------------------------------------------------------------|
| `enabled`                 | Master switch.                                                                                  |
| `self_service`            | If `false`, the `DELETE /auth/account` route returns 403 — only admin status change can delete. |
| `require_password`        | Demand the user's password on the delete call.                                                  |
| `grace_days`              | How long the deleted row stays restorable.                                                      |
| `auto_restore_on_login`   | Silently restore on a credential match within grace.                                            |
| `null_uniques_after_grace`| Worker nulls unique columns so email/username can be reclaimed.                                 |
| `hard_delete_after_grace` | Worker hard-deletes the users row entirely (audit row in `deleted_accounts` is kept).           |
| `move_to_deleted_table`   | Take a JSON snapshot of the users row at delete time.                                           |
| `unique_columns`          | `'auto'` introspects single-column unique indexes; or pass an explicit array.                   |
| `unique_exclude`          | Columns the resolver must never null (typically primary keys).                                  |

## The `deleted_accounts` table

```
id                  bigint, pk
original_user_id    bigint, indexed — NO foreign key
email               varchar, nullable, indexed
username            varchar, nullable, indexed
delete_reason       text,    nullable
snapshot            json     — full users row at delete time
deleted_at          timestamp
scheduled_purge_at  timestamp, indexed
purged_at           timestamp, nullable
timestamps
```

Why no FK back to `users`? Because if `hard_delete_after_grace=true` the
users row eventually disappears, but the audit row must survive forever so
foreign keys in your app tables (orders, transactions, audit events) still
resolve to *something* meaningful.

## Why null the unique columns?

So a new sign-up with the same email/username can succeed after grace. While
the audit row keeps the original values for historical context, the live
users row's `email`/`username`/etc. become `NULL` — invisible to the unique
constraint.

**Important**: those columns on your `users` table must be `nullable`. If
they were created `NOT NULL`, the purge will fail loudly. Migration hint:

```php
$table->string('email')->nullable()->unique();
```

## Auto unique-column discovery

`UniqueColumnResolver` calls `Schema::getIndexes('users')` and picks every
single-column unique index that is not the primary key. It runs once per
request and caches.

Force an explicit list when you want to skip a column:

```php
'unique_columns' => ['email', 'username'],
```

Add to the exclude list when you want auto-discovery but with carve-outs:

```php
'unique_columns' => 'auto',
'unique_exclude' => ['id', 'external_uuid'],
```

## Self-service endpoint

```
DELETE /auth/account
Authorization: Bearer <token>
Content-Type: application/json

{ "password": "...", "reason": "optional free text" }
```

Returns:

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

## Auto-restore on login

A successful credential check on a `status=deleted` user inside the grace
window triggers `AccountDeletionService::restore()`:

- clears `deleted_at` (via Eloquent `restore()` if the User uses `SoftDeletes`)
- flips `account_status` back to `active`
- drops the `deleted_accounts` row
- fires `AccountRestored`
- sends `AccountRestoredNotification`
- continues issuing the token / starting the session normally

The user sees a normal successful login response — no separate restore
endpoint, no extra round-trip.

## Scheduled worker

`PurgeExpiredAccountDeletions` runs **hourly** on the queue the package was
configured with (`auth_system.queue.name`, default `auth-maintenance`). It
chunks expired `deleted_accounts` rows and calls
`AccountDeletionService::purge()` on each. Per-row failures are reported but
do not block the batch.

Make sure your queue worker is running for that queue.

## Events

| Event                    | Fired when                                              |
|--------------------------|---------------------------------------------------------|
| `AccountDeleted`         | Self-service delete or admin-driven status → deleted.   |
| `AccountRestored`        | Login auto-restore (or programmatic `restore()` call).  |
| `AccountPurged`          | Worker permanently anonymises a row.                    |

Listen to them with the standard Laravel `Event::listen` or an
auto-discovered listener under `app/Listeners/`.

## Customising the emails

Each notification has both a Blade view and an FQCN override key — same
pattern as the existing OTP / magic-link emails. See
[customization.md](customization.md#account-emails-v24).

## Required User-model traits

```php
use Illuminate\Database\Eloquent\SoftDeletes;
use Joe404\LaravelAuth\Concerns\HasAccountStatus; // optional helper

class User extends Authenticatable
{
    use HasApiTokens, HasRoles, Notifiable, SoftDeletes, HasAccountStatus;
    // …
}
```

`SoftDeletes` is required for auto-restore. `HasAccountStatus` is optional
sugar — the package reads/writes the status column directly either way.
