# Account Status

Since v2.4 the package ships a configurable status workflow for every user. A
status decides whether a user can log in at all and is checked on every
authenticated request, so flipping a status takes effect immediately — no
waiting for tokens to expire.

## Statuses

The bundled statuses are `active`, `disabled`, `suspended`, `deleted` and
`deactivated`. Each has a distinct intended use:

| Status        | Who sets it      | Behavior                                                                                  |
| ------------- | ---------------- | ----------------------------------------------------------------------------------------- |
| `active`      | Default          | Normal user. Can log in.                                                                  |
| `suspended`   | Admin            | Temporary admin ban. Can carry an `expires_at` for auto-unban. Default temporary-capable. |
| `disabled`    | Admin            | **Meta-style violation ban** — permanent, requires manual admin reactivation. No expiry.  |
| `deleted`     | User (self)      | Soft-deleted with 30-day grace; auto-restores on login during grace, purged after.        |
| `deactivated` | User (self)      | Instagram-style pause. Auto-reactivates the instant the user logs in again. No deadline.  |

The list lives in config so you can add custom ones (e.g. `pending_review`)
without forking the package.

```php
// config/auth_system.php
'account' => [
    'status' => [
        'enabled'       => true,
        'column'        => 'account_status',
        'default'       => 'active',
        'allowed'       => ['active', 'disabled', 'suspended', 'deleted', 'pending_review'],
        'login_blocked' => ['disabled', 'suspended', 'pending_review'],
        'revoke_sessions_on_change' => true,
        'admin_ability' => 'super-admin|admin',
    ],
],
```

`deleted` is **not** in `login_blocked` — it is handled by the deletion
auto-restore flow, see [account-deletion.md](account-deletion.md).

## Schema

`php artisan auth:install` runs an idempotent migration that adds:

| Column              | Type          | Purpose                                   |
| ------------------- | ------------- | ----------------------------------------- |
| `account_status`    | `varchar(32)` | Current status. Defaults to `active`.     |
| `status_changed_at` | `timestamp`   | When the status last changed.             |
| `status_reason`     | `text`        | Optional admin/user-supplied explanation. |
| `deleted_at`        | `timestamp`   | SoftDeletes column (used by deletion).    |

Add the `SoftDeletes` trait to your `User` model so the deletion + restore
flow works.

## Login enforcement

`AuthService::login()` calls `AccountStatusService::assertCanLogin($user)`
after a successful credential check. If the user's status is in
`login_blocked`, the login is rejected with a per-status error key:

| Status      | Error key           | Default English message          |
| ----------- | ------------------- | -------------------------------- |
| `disabled`  | `account_disabled`  | This account has been disabled.  |
| `suspended` | `account_suspended` | This account has been suspended. |

These run through the same translation pipeline as every other auth message —
override per locale via `lang/vendor/auth_system/<locale>/errors.php` or
globally via `config('auth_system.errors.account_disabled', '…')`.

## Per-request enforcement (mid-session bans)

Apply the `auth.active` middleware to any route group that should reject users
whose status changed _after_ they logged in:

```php
Route::middleware(['auth:sanctum', 'auth.active'])->group(function () {
    // …
});
```

Without this middleware, a suspension only blocks the _next_ login — existing
tokens continue to work until they expire or are revoked. With it, the very
next request returns 403.

The middleware is registered automatically by the package's service provider.

## Changing status from code

```php
use Joe404\LaravelAuth\Services\AccountStatusService;

app(AccountStatusService::class)->changeStatus(
    $user,
    'suspended',
    'Spam reports above threshold.',
);
```

Side effects:

- writes `account_status`, `status_changed_at`, `status_reason`
- if the user was `active` and the new status isn't, revokes all sanctum
  tokens + `AuthSessionExtended` rows (toggle with
  `revoke_sessions_on_change`)
- fires `AccountStatusChanged` event
- sends `AccountStatusChangedNotification` if
  `mail.account_notifications_enabled.status_changed` is true (off by default)

## Admin endpoints

```
GET  /auth/admin/users/{id}/status
POST /auth/admin/users/{id}/status   { "status": "...", "reason": "...", "expires_at": "...", "duration_minutes": ... }
```

Gated by the role(s) in `config('auth_system.account.status.admin_ability')`
(default `super-admin|admin`).

## Timed bans (auto-unban)

The admin endpoint accepts an optional expiry — the system flips the user
back to `active` automatically when it elapses.

Two equivalent ways to express the expiry; if both are sent, `expires_at`
wins:

```json
// Suspend until a specific moment
{ "status": "suspended", "reason": "Cooling off", "expires_at": "2026-07-17T12:00:00Z" }

// Suspend for a duration
{ "status": "suspended", "reason": "Cooling off", "duration_minutes": 120 }   // 2 hours
{ "status": "disabled",  "duration_minutes": 43200 }                          // 30 days
```

Omit both for a **permanent** ban (the pre-existing behavior).

### How auto-unban actually fires

Two layers, both default on:

1. **Lazy revert.** Every status read goes through
   `AccountStatusService::current($user)`. If `status_expires_at <= now()`
   and the user isn't already `active`, the package flips them on the spot
   before returning. Means a user can log in the *instant* their ban
   expires — no waiting for the worker.
2. **Scheduled sweep.** Every `auto_unban.sweep_minutes` minutes (default
   5) the `RevertExpiredAccountStatuses` job sweeps every row with an
   elapsed expiry that the lazy path hasn't touched yet and reverts them.

Both paths flow through `changeStatus()`, so `AccountStatusChanged` fires
exactly once per revert (the job is idempotent — if the lazy path already
ran, the worker no-ops).

### Which statuses can be timed?

You whitelist them via `temporary_statuses`. Anything not in the list is
**permanent-only** — passing `expires_at` / `duration_minutes` alongside it
returns 422 with a clear error.

```php
'auto_unban' => [
    // ...
    'temporary_statuses' => ['suspended'],   // default
],
```

Default policy:
- `suspended` → timed-capable. Expiry optional; null expiry = permanent.
- `disabled` → permanent-only. Expiry rejected; admin must manually
  reactivate. Useful when "disabled" carries a heavier connotation in your
  product (e.g. policy violation that requires human review).

Add `disabled` to `temporary_statuses` if you want both kinds of bans to
support expiries.

**Null expiry on a temporary-capable status still means a forever ban** —
the worker only acts when `status_expires_at` is set, so the absence of an
expiry is a permanent suspension by definition. The lazy revert and sweep
both bail out on null.

### Configuration

```php
'account' => [
    'status' => [
        // ...
        'auto_unban' => [
            'enabled'            => true,
            'sweep_minutes'      => 5,
            'temporary_statuses' => ['suspended'],
        ],
    ],
],
```

Or via env: `AUTH_ACCOUNT_AUTO_UNBAN=true`, `AUTH_ACCOUNT_AUTO_UNBAN_SWEEP=5`.

Set `enabled=false` to disable both layers — admins can still set
`status_expires_at` but the package will not act on it.

### Manually clearing an active ban

Calling `changeStatus($user, 'active', ...)` always wipes
`status_expires_at`, regardless of whether a duration was originally set.
A pardon is a pardon.

### Reading the expiry

`GET /auth/admin/users/{id}/status` returns:

```json
{
  "user_id": 42,
  "status": "suspended",
  "status_expires_at": "2026-07-17T12:00:00+00:00",
  "status_changed_at": "2026-05-17T10:00:00.000000Z",
  "status_reason": "Cooling off",
  "allowed": ["active", "disabled", "suspended", "deleted"]
}
```

## Custom statuses

Add the string to `allowed`. If it should block login, also add it to
`login_blocked` and add the matching translation keys:

```php
// config/auth_system.php
'allowed'       => [..., 'pending_review'],
'login_blocked' => [..., 'pending_review'],
```

```php
// resources/lang/en/errors.php (host-published copy)
'account_pending_review' => 'Your account is awaiting review.',
```

The package builds the error key as `account_{status}`.

## `disabled` — admin violation ban (Meta-style)

`disabled` is the heaviest status. It models a Facebook / Instagram-style
account ban: the admin disables for a policy violation, the user cannot log
in, and the status only flips back via deliberate admin action.

- Always permanent — not in `temporary_statuses` by default. Passing an
  `expires_at` or `duration_minutes` for `disabled` returns 422.
- Login is rejected with the `account_disabled` translatable error key.
- The reason is captured in `status_reason` for the admin's records.
- The user has no self-service way out. The intended escape hatch is an
  **appeal workflow** — the user submits an appeal, an admin reviews and
  accepts or rejects, and on accept the admin calls the status endpoint
  to flip back to `active`. The appeal endpoints themselves are not in the
  package yet (they will ship in a later release); for now host apps can
  build them on top of `AccountStatusService::changeStatus()`.

## `deactivated` — user self-pause (Instagram-style)

Distinct from `deleted`: nothing is anonymised, no countdown runs, the user
can come back any time by logging in.

```
POST /auth/account/deactivate
Authorization: Bearer <token>

{ "password": "...", "reason": "optional free text" }
```

What it does:

- writes `account_status = deactivated`, `status_changed_at`, optional `status_reason`
- revokes every sanctum token + every `AuthSessionExtended` row for the user
  (they are signed out everywhere)
- fires `AccountStatusChanged`
- sends `AccountDeactivatedNotification` (toggle:
  `mail.account_notifications_enabled.deactivated`, default true)

What it does NOT do:

- it does not soft-delete the user
- it does not schedule a purge
- it does not touch unique columns

Reactivation is **automatic** on the next successful login. The login flow
detects `status=deactivated`, flips back to `active`, sends
`AccountReactivatedNotification`, and continues issuing the token as if
nothing happened. The user just logs in like normal — no separate
reactivate endpoint, no signed link.

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

If you ever want to turn the auto-flow off (e.g. you want to require a
support ticket to come back), set `auto_reactivate_on_login=false` and add
`deactivated` to `login_blocked` so the login is explicitly rejected.

## Audit log (multi-admin context)

Every status transition — admin, user, or automatic — is persisted to
`account_status_logs` so a second admin can open a user's history and
understand the chain of events without pinging anyone. Admins can also
attach free-form notes without changing status (e.g. "user emailed support
twice — waiting on product reply").

Every row records: who acted (`actor_type` + `actor_id`), what happened
(`action` = `status_change` or `note`), the transition (`from_status` →
`to_status`), `reason` (short tag), `comment` (long admin note), `source`
(context tag like `admin_endpoint`, `self_deactivate`, `auto_unban_lazy`,
`login_auto_restore`, `purge_worker`, `admin_note`), `expires_at`, IP +
user-agent if a request is in scope.

### Endpoints

```
GET  /auth/admin/users/{id}/status/history
GET  /auth/admin/users/{id}/status/history?actor_type=admin&from=2026-01-01&page=2&per_page=50

POST /auth/admin/users/{id}/notes
      { "comment": "Required free-form note.", "reason": "optional short tag" }

POST /auth/admin/users/{id}/status
      { "status": "...", "reason": "...", "comment": "Optional admin note attached to this change." }
```

Both audit endpoints are gated by the same role as the status endpoint
(`account.status.admin_ability`, default `super-admin|admin`).

### Source tags shipped out of the box

| Source                  | Fires when …                                                         |
| ----------------------- | -------------------------------------------------------------------- |
| `admin_endpoint`        | Admin hits `POST /auth/admin/users/{id}/status`.                     |
| `admin_note`            | Admin hits `POST /auth/admin/users/{id}/notes`.                      |
| `self_deactivate`       | User hits `POST /auth/account/deactivate`.                           |
| `self_delete`           | User hits `DELETE /auth/account`.                                    |
| `login_auto_restore`    | Auto-restore on login for a `deleted` user inside grace.             |
| `login_auto_reactivate` | Auto-reactivate on login for a `deactivated` user.                   |
| `auto_unban_lazy`       | Lazy revert inside `AccountStatusService::current()`.                |
| `auto_unban_sweep`      | Sweep worker reverts an expired ban.                                 |
| `purge_worker`          | Purge worker permanently anonymises a deleted row.                   |

Host apps can pass any custom string into `AccountStatusService::changeStatus(...,
['source' => 'my_tag'])` — the column is free-form.

### Config — every part is opt-out

```php
'audit' => [
    'enabled'              => true,                  // master switch
    'table'                => 'account_status_logs', // override table name
    'log_status_changes'   => true,                  // log status transitions
    'log_system_actions'   => true,                  // include actor=system rows
    'capture_request_meta' => true,                  // ip + user_agent

    'notes' => [
        'enabled' => true,                           // POST .../notes endpoint
    ],
    'history' => [
        'enabled'          => true,                  // GET .../status/history endpoint
        'default_per_page' => 20,
        'max_per_page'     => 100,
    ],

    'retention_days' => null,                        // null = forever; N = daily cleanup
],
```

Env equivalents follow the `AUTH_ACCOUNT_AUDIT_*` prefix — see
`config/auth_system.php` comments for the full list.

### When does audit write?

- `enabled=false` → nothing is written, both endpoints return 404.
- `log_status_changes=false` → status transitions stop being logged but
  admin notes (which are `action=note`) still are.
- `log_system_actions=false` → admin + user transitions are logged but
  automatic ones (lazy revert, sweep, purge, login auto-restore, login
  auto-reactivate) are silently dropped.

All writes are wrapped in try/catch — a logger failure must never block
the underlying action.

## Cheat sheet — picking the right status

- Cooling-off, will lift itself → `suspended` + `duration_minutes`.
- Policy violation, requires human review to lift → `disabled`.
- User wants to take a break and come back → `deactivated`.
- User wants to leave but might change their mind → `deleted` (30-day grace).

## Disabling the feature

Set `account.status.enabled = false`. Login and middleware skip status checks
entirely. The columns stay in the schema — disabling is a runtime decision,
not a teardown.
