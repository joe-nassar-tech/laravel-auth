# Installation Guide

Complete setup guide for `joe-404/laravel-auth`.

---

## Table of Contents

- [TL;DR](#tldr)
- [What gets installed automatically](#what-gets-installed-automatically)
- [What `auth:install` does step by step](#what-authinstall-does-step-by-step)
- [Installer flags](#installer-flags)
- [Host app changes you must make manually](#host-app-changes-you-must-make-manually)
- [Optional packages](#optional-packages)
- [Manual install (without auth:install)](#manual-install-without-authinstall)
- [Customising the route prefix](#customising-the-route-prefix)
- [Verifying the install](#verifying-the-install)
- [Troubleshooting](#troubleshooting)

---

## TL;DR

```bash
composer require joe-404/laravel-auth
php artisan auth:install
```

Then in `app/Models/User.php`:

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

Set a mail driver and `AUTH_MODE` in `.env`, and you're ready.

---

## What gets installed automatically

`composer require joe-404/laravel-auth` installs these packages as hard dependencies — you do **not** need to require them separately:

| Package | Purpose |
|---|---|
| `laravel/sanctum ^4.0` | Access tokens and SPA session cookies |
| `laravel/socialite ^5.0` | Google OAuth flow |
| `spatie/laravel-permission ^6.0` | Roles and permissions (`HasRoles`, `assignRole`, `User::role('admin')`) |
| `jenssegers/agent ^2.6` | User-Agent parsing for session device tracking |
| `pragmarx/google2fa ^8.0` *(v2.6)* | TOTP authenticator-app 2FA (RFC 6238) |
| `bacon/bacon-qr-code ^3.0` *(v2.6)* | Server-rendered QR codes for TOTP enrollment |

Their service providers are discovered automatically — no `config/app.php` edit needed.

### Database tables created

After `auth:install` runs, your database has these tables:

| Table | Owner |
|---|---|
| `auth_otp_codes` | `joe-404/laravel-auth` |
| `auth_sessions_extended` | `joe-404/laravel-auth` |
| `auth_refresh_tokens` | `joe-404/laravel-auth` |
| `auth_social_accounts` | `joe-404/laravel-auth` |
| `auth_api_tokens` | `joe-404/laravel-auth` |
| `account_status_logs` | `joe-404/laravel-auth` |
| `deleted_accounts` | `joe-404/laravel-auth` |
| `auth_two_factor_methods` *(v2.6)* | `joe-404/laravel-auth` |
| `auth_two_factor_backup_codes` *(v2.6)* | `joe-404/laravel-auth` |
| `auth_two_factor_challenges` *(v2.6)* | `joe-404/laravel-auth` |
| `auth_trusted_devices` *(v2.6)* | `joe-404/laravel-auth` |
| `auth_phone_otp_codes` *(v2.6)* | `joe-404/laravel-auth` |
| `users` (altered: `last_login_at`, `is_active`, `account_status`, `status_changed_at`, `status_reason`, `status_expires_at`, `deleted_at`, and *(v2.6)* `phone`, `phone_verified_at`, `two_factor_required`) | `joe-404/laravel-auth` |
| `personal_access_tokens` | `laravel/sanctum` |
| `roles`, `permissions`, `model_has_roles`, `role_has_permissions`, `model_has_permissions` | `spatie/laravel-permission` |

---

## What `auth:install` does step by step

The installer runs steps in this exact order, which matters — running them out of order is the most common source of install errors.

### 1. Sanity-check Composer dependencies

Confirms all four required packages are loadable. If any are missing, the command prints the exact `composer require` command and aborts — it will not proceed to publishing with a broken dependency.

### 2. Publish the package config

Copies `config/auth_system.php` to your application. Skip with `--force` to overwrite an existing copy.

### 3. Publish dependency migrations

Publishes migration stubs from:
- `laravel/sanctum` → creates `personal_access_tokens`
- `spatie/laravel-permission` → creates `roles`, `permissions`, and the three pivot tables

### 4. Run `php artisan migrate --force`

Runs all pending migrations. This creates the dependency tables from step 3, and runs all package-owned migrations from `vendor/joe-404/laravel-auth/database/migrations/`.

### 5. Seed the default roles

Runs `AuthRolesSeeder`, which calls `Role::firstOrCreate()` for each role in `config('auth_system.roles.seeded_roles')`. Defaults are `super-admin`, `admin`, `user`. The seeder is idempotent — safe to re-run.

### 6. Wire the Reverb channel stub

Appends the `auth.verification.{tempToken}` channel definition to `routes/channels.php`. This is idempotent — if the definition is already present, it is skipped.

### Is it safe to re-run?

Yes. Publishing without `--force` is a no-op on existing files, `migrate` only runs pending migrations, and the seeder uses `firstOrCreate`. Re-running after a failed install picks up where it left off.

---

## Installer flags

| Flag | Effect |
|---|---|
| `--force` | Overwrite existing published files (`config/auth_system.php`, migrations). Use when updating to a new version that ships config changes. |
| `--skip-migrations` | Publish config only; do not run `migrate`. Useful in CI pipelines or multi-step deploy scripts. |
| `--skip-seed` | Skip `AuthRolesSeeder`. Use when you seed roles from your own seeder. |

---

## Host app changes you must make manually

`auth:install` cannot edit your `User` model or your Sanctum config. Do these after the command finishes.

### 1. Add traits to User model

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\SoftDeletes;
use Joe404\LaravelAuth\Concerns\HasAccountStatus;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasRoles, Notifiable, SoftDeletes, HasAccountStatus;

    protected $fillable = [
        'name', 'email', 'password',
        // …your fields…
        // v2.6 — required if you enable the phone / 2FA features:
        'phone', 'phone_verified_at', 'two_factor_required',
    ];

    protected $casts = [
        'email_verified_at'   => 'datetime',
        // v2.6:
        'phone_verified_at'   => 'datetime',
        'two_factor_required' => 'bool',
    ];
}
```

**Why each trait is needed:**

| Trait | Without it |
|---|---|
| `HasApiTokens` | Token issuance silently returns null |
| `HasRoles` | `User::role('admin')` throws; `assignRole()` fails |
| `SoftDeletes` | The account deletion auto-restore flow cannot work |
| `HasAccountStatus` | Optional — convenience methods (`$user->isActive()`, `$user->isSuspended()`, etc.) won't be available, but the package reads/writes the status column directly either way |

> **v2.6 columns must be `$fillable`.** The package writes `phone` during registration and `phone_verified_at` / `two_factor_required` during the phone + 2FA flows via mass-assignment. If you enable those features without adding the columns to `$fillable`, the writes are silently dropped by Laravel's guard. The columns are harmless to add even if you leave the features disabled.

### 2. Set Sanctum stateful domains (SPA / cookie auth only)

If you are using web or `both` auth mode with a browser SPA:

```php
// config/sanctum.php
'stateful' => explode(',', env('SANCTUM_STATEFUL_DOMAINS', 'localhost,127.0.0.1')),
```

```env
SANCTUM_STATEFUL_DOMAINS=app.example.com,localhost
```

Mobile clients using Bearer tokens do not need this.

### 3. Set the minimum .env

```env
# Core
AUTH_MODE=both                   # api | web | both
AUTH_VERIFICATION_METHOD=both    # otp | magic_link | both

# Mail (required — the package sends OTPs and magic links)
MAIL_MAILER=smtp
MAIL_FROM_ADDRESS=hello@yourapp.com
MAIL_FROM_NAME="${APP_NAME}"
```

See [docs/configuration.md](configuration.md) for the complete `.env` reference.

---

## Optional packages

Install only the ones you need. `auth:install` prints a notice if they are missing but does not abort.

| Package | What it enables |
|---|---|
| `laravel/reverb` | Real-time WebSocket push when a user's email is verified. The package broadcasts on `private-auth.verification.{tempToken}`. |
| `laravel/horizon` | Web dashboard for the `auth-maintenance` queue (OTP cleanup, refresh token cleanup, deletion purge jobs). |
| `laravel/telescope` | Request inspector during development. |

```bash
composer require laravel/reverb
composer require laravel/horizon
composer require laravel/telescope
```

---

## Manual install (without auth:install)

Use this when your host app forbids running artisan commands from `vendor:publish`, or when you need explicit control over migration ordering.

```bash
# 1. Install the package
composer require joe-404/laravel-auth

# 2. Publish the package config
php artisan vendor:publish --tag=auth-config

# 3. Publish Sanctum migrations
php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"

# 4. Publish Spatie Permission migrations
php artisan vendor:publish \
    --provider="Spatie\Permission\PermissionServiceProvider" \
    --tag=permission-migrations

# 5. Run all migrations
php artisan migrate

# 6. Seed the default roles
php artisan db:seed --class="Joe404\LaravelAuth\Database\Seeders\AuthRolesSeeder"

# 7. (Optional) Publish language files
php artisan vendor:publish --tag=auth-lang

# 8. (Optional) Publish email view templates
php artisan vendor:publish --tag=auth-views
```

Then make the same User model and `.env` changes from the section above.

---

## Customising the route prefix

By default all routes are mounted at `/auth`. To mount them elsewhere (e.g. inside a versioned API group):

```php
// config/auth_system.php
'routes' => [
    'prefix'     => 'api/v1/auth',   // changes /auth/login → /api/v1/auth/login
    'register'   => true,            // set false to disable route registration entirely
    'middleware' => ['api'],         // default middleware group
],
```

Or via `.env`:

```env
AUTH_ROUTE_PREFIX=api/v1/auth
AUTH_ROUTES_ENABLED=true
```

> If you mount under a prefix that already includes the `api` middleware group (e.g. inside a `Route::prefix('api')->middleware('api')` group), set `middleware` to `[]` to avoid double-applying it.

---

## Verifying the install

Run these three checks after installing. If all pass, the install is complete.

```bash
# 1. Confirm all package tables are migrated
php artisan migrate:status | findstr "auth_"
# Every auth_ table should show "Yes" or "Ran"

# 2. Confirm roles were seeded
php artisan tinker --execute="echo Spatie\Permission\Models\Role::pluck('name');"
# Prints: ["super-admin","admin","user"]

# 3. Confirm routes are registered
php artisan route:list --path=auth
# Lists /auth/register, /auth/login, /auth/me, etc.
```

---

## Troubleshooting

### `Table 'roles' doesn't exist` when seeding

You ran the seeder before Spatie Permission's migrations were applied.

```bash
php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider" --tag=permission-migrations
php artisan migrate
php artisan db:seed --class="Joe404\LaravelAuth\Database\Seeders\AuthRolesSeeder"
```

Or simply run `php artisan auth:install` — it does these three in order.

### `Class … AuthRolesSeeder does not exist`

The Composer autoloader hasn't picked up the package. Run:

```bash
composer dump-autoload
```

### `Role does not exist` when calling `assignRole('admin')`

Either the role was not in `auth_system.roles.seeded_roles` when the seeder ran, or the seeder hasn't run at all.

```bash
# Check what roles exist
php artisan tinker --execute="echo Spatie\Permission\Models\Role::pluck('name');"

# Re-seed if the role is missing
php artisan db:seed --class="Joe404\LaravelAuth\Database\Seeders\AuthRolesSeeder"
```

Alternatively create the role manually:

```php
\Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
```

### `auth:install` fails at the migrate step

Run the install in two passes:

```bash
php artisan auth:install --skip-migrations   # publish only
# fix your DB issue
php artisan migrate
php artisan auth:install --skip-migrations   # picks up at seed step
```

### "Optional packages not installed" notice

This is informational. The install continues normally. Install optional packages individually when you need them:

```bash
composer require laravel/reverb     # real-time verification events
composer require laravel/horizon    # queue dashboard
composer require laravel/telescope  # dev request inspector
```

### Tokens come back `null` after login

`HasApiTokens` is missing from the `User` model. Add it (see the traits section above).

### `User::role('admin')` throws a `BadMethodCallException`

`HasRoles` is missing from the `User` model.

### Account deletion auto-restore does not work

`SoftDeletes` is missing from the `User` model. The package calls `$user->restore()` on login, which requires the trait.

### MySQL strict mode error on `deleted_accounts` migration

Use v2.4.2 or later. Earlier versions declared timestamp columns as `NOT NULL` without a default, which MySQL strict mode rejects. See [docs/upgrading.md](upgrading.md) for details.
