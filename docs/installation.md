# Installation Guide

End-to-end install for `joe-404/laravel-auth`, including everything the
package needs in your host Laravel app.

## TL;DR

```bash
composer require joe-404/laravel-auth
php artisan auth:install
```

Then in `app/Models/User.php`:

```php
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasRoles, Notifiable;
}
```

Set a mail driver and `AUTH_MODE` in `.env`, and you're done. The
end-to-end guide below explains exactly what `auth:install` does and how
to recover when one of its steps fails partway through.

---

## What `composer require` installs

The package declares hard dependencies in its `composer.json`, so they
install automatically alongside it:

| Package | Why |
|---------|-----|
| `laravel/sanctum` `^4.0`           | Issues access tokens and session cookies. |
| `laravel/socialite` `^5.0`         | Optional Google OAuth flow. |
| `spatie/laravel-permission` `^6.0` | Role + permission storage; `assignRole('admin')`, `User::role('admin')->…`. |
| `jenssegers/agent` `^2.6`          | User-Agent parsing for the device/session columns. |

You do **not** need to `composer require` any of these yourself — Composer
resolves them transitively. The same is true for their service providers:
Laravel's package discovery picks them up automatically.

### Optional packages (install only if you need the feature)

| Package | Feature unlocked |
|---------|------------------|
| `laravel/reverb`    | Real-time WebSocket notification when a user verifies their email. The package broadcasts on `private-auth.verification.{tempToken}`. |
| `laravel/horizon`   | A web dashboard for the package's `auth-maintenance` queue (OTP / refresh-token cleanup jobs). |
| `laravel/telescope` | Request inspector during development. |

If any of these are missing, `auth:install` prints a notice but does not
abort — the rest of the install completes normally.

---

## What `php artisan auth:install` does

It is one command on purpose — running steps in the wrong order is the #1
source of install-time errors. Internally it performs, **in this order**:

1. **Sanity-check required Composer packages.** Fails loud with the exact
   `composer require …` command if any of the four required dependencies
   above is not loadable.
2. **Publishes the package config** to `config/auth_system.php`.
3. **Publishes dependency migrations:**
   - `laravel/sanctum` → `personal_access_tokens` table.
   - `spatie/laravel-permission` → `roles`, `permissions`,
     `model_has_roles`, `role_has_permissions`, `model_has_permissions`
     tables.
4. **Runs `php artisan migrate --force`.** Creates the dependency tables
   above **plus** the six package-owned tables loaded from
   `vendor/joe-404/laravel-auth/database/migrations/`:
   - `auth_otp_codes`
   - `auth_sessions_extended`
   - `auth_refresh_tokens`
   - `auth_social_accounts`
   - `auth_api_tokens`
   - alterations to your `users` table (`last_login_at`, `is_active`)
5. **Seeds the default roles** via `AuthRolesSeeder`. By default this
   creates `super-admin`, `admin`, `user` — configurable via
   `auth_system.roles.seeded_roles`. The seeder is idempotent and pre-flights
   the `roles` table existence with a helpful error if it's missing.
6. **Wires the Reverb channel stub** by appending the
   `auth.verification.{tempToken}` channel definition to
   `routes/channels.php`. Idempotent — skipped if already present.

### Flags

| Flag | Effect |
|------|--------|
| `--force`            | Overwrite existing published files (`config/auth_system.php`, migrations). |
| `--skip-migrations`  | Publish only; do not run `migrate`. Useful in CI / multi-step deploys. |
| `--skip-seed`        | Skip `AuthRolesSeeder`. Useful when seeding roles from your own seeder. |

### Re-running

`auth:install` is safe to run multiple times. Publishing without `--force`
is a no-op, `migrate` only runs pending migrations, and the seeder uses
`Role::firstOrCreate()`.

---

## Host app changes you still need to make manually

`auth:install` cannot edit your `User` model or `config/sanctum.php`.
After the command finishes:

### 1. Add the required traits to `User`

```php
namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasRoles, Notifiable;

    // existing $fillable, $hidden, casts, etc.
}
```

Without `HasRoles`, `User::role('admin')` will throw. Without
`HasApiTokens`, token issuance silently produces null.

### 2. Add your frontend domain to Sanctum's stateful list

For SPA / cookie-based auth:

```php
// config/sanctum.php
'stateful' => explode(',', env('SANCTUM_STATEFUL_DOMAINS', 'localhost,127.0.0.1')),
```

```env
SANCTUM_STATEFUL_DOMAINS=app.example.com,localhost
```

### 3. Set the minimum `.env`

```env
AUTH_MODE=both
AUTH_VERIFICATION_METHOD=both

MAIL_MAILER=smtp
MAIL_FROM_ADDRESS=hello@yourapp.com
MAIL_FROM_NAME="${APP_NAME}"
```

See `docs/configuration.md` for the full `.env` reference.

---

## Manual install (when you cannot use `auth:install`)

If your host app forbids `vendor:publish` from a command, has custom
migration ordering, or you want explicit control over each step:

```bash
# 1. Install the package
composer require joe-404/laravel-auth

# 2. Publish the package's config
php artisan vendor:publish --tag=auth-config

# 3. Publish Sanctum's migration
php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"

# 4. Publish Spatie Permission's migration
php artisan vendor:publish \
    --provider="Spatie\Permission\PermissionServiceProvider" \
    --tag=permission-migrations

# 5. Run all migrations
php artisan migrate

# 6. Seed the default roles
php artisan db:seed --class="Joe404\LaravelAuth\Database\Seeders\AuthRolesSeeder"

# 7. (Optional) Append the Reverb channel auth stub
php artisan vendor:publish --tag=auth-stubs   # writes stubs/vendor/joe-404/laravel-auth/channels.stub
# then copy its contents into routes/channels.php
```

---

## Troubleshooting

### `SQLSTATE[42S02]: Base table or view not found: 1146 Table '…roles' doesn't exist`

You ran `db:seed` for `AuthRolesSeeder` **before** Spatie Permission's
migration was published and applied. Fix:

```bash
php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider" --tag=permission-migrations
php artisan migrate
php artisan db:seed --class="Joe404\LaravelAuth\Database\Seeders\AuthRolesSeeder"
```

Or just run `php artisan auth:install` — it does the same three commands
in order. Starting with v2.3 the seeder pre-flights this and prints the
same hint before the SQL error fires.

### `Class … Joe404\LaravelAuth\Database\Seeders\AuthRolesSeeder does not exist`

The autoloader has not picked up the package. Run:

```bash
composer dump-autoload
```

### `Role does not exist` on `assignRole('admin')`

Your `auth_system.roles.seeded_roles` config does not include the role
you're trying to assign, or the seeder has not run. Either add the role
to the config and re-run `db:seed`, or call
`Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web'])`
before `assignRole`.

### `auth:install` fails at the migrate step

Re-run with `--skip-migrations`, fix the underlying DB issue, then run
`php artisan migrate` followed by `php artisan auth:install --skip-migrations`
(the second run will pick up at the seed step without re-publishing).

### "Optional packages not installed" warning

Informational only. Install just the ones you want:

```bash
composer require laravel/reverb     # real-time verification events
composer require laravel/horizon    # queue dashboard
composer require laravel/telescope  # dev request inspector
```

---

## Verifying the install

```bash
php artisan migrate:status | grep -E "auth_otp_codes|auth_sessions|auth_refresh|auth_social|auth_api|permission_tables|sanctum"
# All rows should show "Ran".

php artisan tinker --execute="echo Spatie\Permission\Models\Role::pluck('name');"
# Prints ["super-admin", "admin", "user"] (or your configured roles).

php artisan route:list --path=auth | head -20
# Confirms /auth/register, /auth/login, /auth/me, etc. are registered.
```

If all three commands above succeed, the install is good.
