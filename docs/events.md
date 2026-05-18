# Events & Listeners

The package fires a Laravel event at every significant point in the auth lifecycle. Your host app subscribes to the events it cares about and runs its own logic — wallet seeding, audit rows, welcome emails, analytics, webhooks — without modifying the package or forking controllers.

---

## Table of Contents

1. [Why events?](#1-why-events)
2. [All events reference](#2-all-events-reference)
3. [Listening to events (auto-discovery)](#3-listening-to-events-auto-discovery)
4. [Multiple listeners on the same event](#4-multiple-listeners-on-the-same-event)
5. [Queueing a listener](#5-queueing-a-listener)
6. [Worked example](#6-worked-example)
7. [Common pitfall — double registration](#7-common-pitfall--double-registration)
8. [Disabling auto-discovery](#8-disabling-auto-discovery)

---

## 1. Why events?

A typical config-driven library asks you to set a callback:

```php
'on_user_registered' => \App\Hooks\MyHook::class,  // not how this package works
```

That works for one hook per slot. The moment a second team needs the same trigger (auditing, billing, messaging), you're wrapping classes inside classes.

Events solve this with N:M wiring. The package fires one event; any number of independent listeners — yours, a teammate's, a third-party package's — can react without knowing about each other.

---

## 2. All events reference

All events live under the `Joe404\LaravelAuth\Events\` namespace.

### Core auth events

| Event | When fired | Payload |
|---|---|---|
| `EmailVerified` | After `POST /auth/register/complete` — user row exists, role assigned, transaction committed | `$user`, `$tempToken` |
| `UserLoggedIn` | Successful login (password or social) | `$user`, `$request` |
| `UserLoggedOut` | Any logout (single session or `logout/all`) | — |
| `PasswordChanged` | Password reset confirmation **or** authenticated password change | `$user` |
| `SuspiciousLoginDetected` | Login from a device the package hasn't seen before for this user | `$user`, `$ip`, `$browser`, `$os`, `$city`, `$country` |

### Account lifecycle events (v2.4)

| Event | When fired | Payload |
|---|---|---|
| `AccountStatusChanged` | Any status change (admin, user, or automatic) | `$user`, `$from`, `$to`, `$reason`, `$expiresAt` |
| `AccountDeleted` | User calls `DELETE /auth/account` | `$user`, `$gracePeriodDays`, `$scheduledPurgeAt` |
| `AccountRestored` | Login auto-restore during grace period | `$user` |
| `AccountPurged` | Purge worker permanently anonymises the row after grace | `$userId`, `$email` (from snapshot) |

### Payload access

Event payloads are public properties on the event class:

```php
use Joe404\LaravelAuth\Events\EmailVerified;
use Joe404\LaravelAuth\Events\AccountStatusChanged;
use Joe404\LaravelAuth\Events\UserLoggedIn;

// EmailVerified
$event->user        // App\Models\User instance
$event->tempToken   // string — the UUID from step 1 of registration

// UserLoggedIn
$event->user        // User
$event->request     // Illuminate\Http\Request

// AccountStatusChanged
$event->user        // User
$event->from        // string — previous status
$event->to          // string — new status
$event->reason      // string|null
$event->expiresAt   // Carbon|null — for timed bans

// AccountDeleted
$event->user              // User (still accessible during grace period)
$event->gracePeriodDays   // int
$event->scheduledPurgeAt  // Carbon

// AccountPurged
$event->userId  // int — original user ID (row may be hard-deleted)
$event->email   // string|null — from the deleted_accounts snapshot
```

---

## 3. Listening to events (auto-discovery)

Laravel 11+ auto-discovers listeners in `app/Listeners/` by reading the type-hint of each class's `handle()` method. No service provider registration needed.

**Zero-config listener:**

```php
<?php

namespace App\Listeners;

use Joe404\LaravelAuth\Events\EmailVerified;

class GrantSignupBonus
{
    public function handle(EmailVerified $event): void
    {
        $user = $event->user;
        // Run any host-app logic that should happen at registration
        $user->wallet()->create(['balance' => 0]);
    }
}
```

Drop the file in `app/Listeners/` and the next time `EmailVerified` fires, this runs.

**Verify wiring:**

```bash
php artisan event:list --event="Joe404\LaravelAuth\Events\EmailVerified"
```

All registered listeners for that event are printed. Use this to confirm a new listener is picked up, or to spot an accidental double-registration.

---

## 4. Multiple listeners on the same event

Drop additional files in `app/Listeners/`. Order is not guaranteed; they run independently.

```php
// app/Listeners/SeedFanWallet.php
class SeedFanWallet
{
    public function handle(EmailVerified $event): void
    {
        $event->user->wallet()->create(['balance' => 0]);
    }
}

// app/Listeners/RecordRegistrationAudit.php
class RecordRegistrationAudit
{
    public function handle(EmailVerified $event): void
    {
        AuditLog::create(['event' => 'registered', 'user_id' => $event->user->id]);
    }
}

// app/Listeners/SendBrandedWelcomeEmail.php
class SendBrandedWelcomeEmail implements ShouldQueue
{
    public function handle(EmailVerified $event): void
    {
        Mail::to($event->user)->send(new WelcomeMailable($event->user));
    }
}
```

All three run on every `EmailVerified` dispatch. Each team owns and maintains their listener independently.

---

## 5. Queueing a listener

Implement `ShouldQueue` and the listener runs in the background instead of inside the HTTP request. Recommended for anything that takes more than a few hundred milliseconds (email sending, analytics calls, webhook delivery).

```php
<?php

namespace App\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Joe404\LaravelAuth\Events\EmailVerified;

class SendBrandedWelcomeEmail implements ShouldQueue
{
    public string $queue = 'mail';
    public int $tries = 3;
    public int $backoff = 30;  // seconds between retry attempts

    public function handle(EmailVerified $event): void
    {
        Mail::to($event->user)->send(new WelcomeMailable($event->user));
    }

    public function failed(EmailVerified $event, \Throwable $exception): void
    {
        // Called when all retries are exhausted — log, alert, etc.
        Log::error('Welcome email failed', ['user_id' => $event->user->id]);
    }
}
```

---

## 6. Worked example

**Scenario:** a creator platform where three things must happen the moment a fan registers:

1. Seed a wallet (every fan starts with 0 balance)
2. Write a compliance audit row
3. Send a branded welcome email (queued — don't slow down the response)

**Old approach:** one fat controller that does all three. Every change touches the same file.

**Events approach:** each team owns a tiny listener.

```
app/Listeners/SeedFanWallet.php             → creates wallet (synchronous, fast)
app/Listeners/RecordRegistrationAudit.php   → writes audit row (synchronous, fast)
app/Listeners/SendWelcomeEmail.php          → queues the email (ShouldQueue)
```

```php
// app/Listeners/SeedFanWallet.php
class SeedFanWallet
{
    public function handle(EmailVerified $event): void
    {
        $event->user->wallet()->create(['balance' => 0]);
    }
}
```

```php
// app/Listeners/RecordRegistrationAudit.php
class RecordRegistrationAudit
{
    public function handle(EmailVerified $event): void
    {
        RegistrationAudit::create([
            'user_id'    => $event->user->id,
            'ip_address' => request()->ip(),
            'registered_at' => now(),
        ]);
    }
}
```

```php
// app/Listeners/SendWelcomeEmail.php
class SendWelcomeEmail implements ShouldQueue
{
    public string $queue = 'mail';

    public function handle(EmailVerified $event): void
    {
        Mail::to($event->user)->queue(new WelcomeMailable($event->user));
    }
}
```

The package, the controllers, and the other listeners are completely untouched regardless of which listener changes.

---

## 7. Common pitfall — double registration

Because auto-discovery already binds your listener via the type-hint, an additional manual `Event::listen()` call in a service provider registers it **a second time**. The `handle()` method then runs twice for every dispatch — duplicate audit rows, duplicate emails, double wallet seeds.

**Symptom:** everything looks correct but effects happen twice.

**Fix:** remove the manual `Event::listen()` call and rely on auto-discovery alone.

```php
// WRONG — do not add this if using auto-discovery
Event::listen(EmailVerified::class, SeedFanWallet::class);

// RIGHT — just drop the file in app/Listeners/ and let auto-discovery do it
```

**Verify with:**

```bash
php artisan event:list --event="Joe404\LaravelAuth\Events\EmailVerified"
```

If your listener appears twice in the output, you have a double registration.

---

## 8. Disabling auto-discovery

If your team prefers explicit registration over auto-discovery, opt out in `bootstrap/app.php`:

```php
->withEvents(discover: [])
```

Then register listeners manually in any service provider:

```php
use Illuminate\Support\Facades\Event;
use Joe404\LaravelAuth\Events\EmailVerified;
use App\Listeners\SeedFanWallet;

Event::listen(EmailVerified::class, SeedFanWallet::class);
```

The package fires events the same way regardless — only the registration mechanism changes.
