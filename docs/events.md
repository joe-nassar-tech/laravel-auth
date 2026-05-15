# Events & Listeners

The package never reaches into your application code directly. Instead, every
significant moment in the auth lifecycle is broadcast as a Laravel event, and
your host app subscribes to whichever ones it cares about. This keeps the
package self-contained — and lets you bolt on host-specific behaviour
(create a wallet, write an audit row, send a custom welcome email, push to
analytics, fan out to a webhook) **without forking the package**.

## Why events, not callbacks

A typical config-driven library asks you to point a config key at your
own class:

```php
'on_email_verified' => \App\Hooks\MyHook::class,    // not how this package works
```

That works for *one* hook per slot. The moment a second team needs the same
trigger (auditing wants in, billing wants in, messaging wants in), config
slots become a bottleneck — you end up wrapping classes inside classes.

Events solve that with N:M wiring. The package fires one event; any number
of listeners — yours, a teammate's, a third-party package's — can react,
independently, in any order, without any of them knowing about the others.

## Lifecycle event reference

| Event | When fired | Payload |
|-------|-----------|---------|
| `EmailVerified`           | After `POST /auth/register/complete` succeeds — the user row exists, the role is assigned, the registration transaction has committed. | `$user`, `$tempToken` |
| `UserLoggedIn`            | Successful login (password or social).                                                                                                  | `$user`, `$request`   |
| `UserLoggedOut`           | Any logout (single session or `logout/all`).                                                                                            | —                     |
| `PasswordChanged`         | Password reset confirmation **or** authenticated password change.                                                                       | `$user`               |
| `SuspiciousLoginDetected` | Login from a device the package has not seen before for this user.                                                                      | `$user`, `$ip`, `$browser`, `$os`, `$city`, `$country` |

All events live under the `Joe404\LaravelAuth\Events\` namespace.

## How your listener gets wired (auto-discovery)

Laravel 11+ scans `app/Listeners/` at boot and reads each class's `handle()`
method signature. **The first parameter's type-hint is the event** — Laravel
auto-registers the link. You do not need to add anything to a service
provider.

A complete, zero-config listener:

```php
<?php

namespace App\Listeners;

use Joe404\LaravelAuth\Events\EmailVerified;

class GrantSignupBonus
{
    public function handle(EmailVerified $event): void
    {
        // $event->user is the freshly-created user.
        // Run any host-app logic that should follow registration.
    }
}
```

That file is enough. The next time `EmailVerified` fires, this listener runs.

### Verifying the wiring

```bash
php artisan event:list --event="Joe404\LaravelAuth\Events\EmailVerified"
```

Every registered listener for the event prints in the output. Use this to
confirm a new listener is picked up — or to spot an accidental
double-registration.

### Common pitfall: registering the same listener twice

Because auto-discovery already binds your listener via the type-hint, an
**additional** manual `Event::listen(...)` call in a service provider
registers the listener a *second* time, and `handle()` runs twice for
every dispatch. Symptoms include duplicate audit rows, duplicate emails,
or `firstOrCreate` rows that look as if they "succeeded twice."

If you see duplicates: remove the manual `Event::listen()` call and rely
on auto-discovery alone.

## Multiple listeners on the same event

Drop more files into `app/Listeners/` — order is not guaranteed and they
run independently:

```php
class GrantSignupBonus       { public function handle(EmailVerified $e): void { /* ... */ } }
class SendBrandedWelcomeMail { public function handle(EmailVerified $e): void { /* ... */ } }
class TrackSignupInAnalytics { public function handle(EmailVerified $e): void { /* ... */ } }
```

All three run on every `EmailVerified` dispatch.

## Queueing a listener

Implement `ShouldQueue` and the listener runs on your queue worker
instead of inside the request:

```php
use Illuminate\Contracts\Queue\ShouldQueue;
use Joe404\LaravelAuth\Events\EmailVerified;

class SendBrandedWelcomeMail implements ShouldQueue
{
    public string $queue = 'mail';

    public function handle(EmailVerified $event): void
    {
        // ... long-running work, retried automatically on failure
    }
}
```

Recommended for anything that takes more than a few hundred milliseconds
(network calls to email/SMS providers, analytics, third-party webhooks).
The `EmailVerified` dispatch happens inside the registration's HTTP
request, so anything you do synchronously stretches the response time.

## Worked example — the real-world pattern

Suppose your platform needs three things to happen the moment a user
finishes registration:

1. Create a wallet row (every fan starts with a 0-balance wallet).
2. Write an audit row (compliance / fraud team needs a log).
3. Send a branded welcome email (marketing team's domain).

The "old" way would be one giant controller that does all three after
calling the package — every change requires editing that controller, and
the wallet team, audit team, and marketing team all touch the same file.

The events way: each team owns a tiny listener.

```
app/Listeners/SeedFanWallet.php          → creates the wallet
app/Listeners/RecordRegistrationAudit.php → writes the audit row
app/Listeners/SendWelcomeEmail.php        → queues the welcome email
```

Each handles `EmailVerified`, each is auto-discovered, each can be
edited / disabled / re-tested independently. The package, the controller,
and the other listeners stay untouched.

## Disabling auto-discovery (rarely needed)

If your team prefers explicit registration over auto-discovery, opt out
in `bootstrap/app.php`:

```php
->withEvents(discover: [])
```

Then register listeners manually anywhere in your service providers:

```php
Event::listen(EmailVerified::class, GrantSignupBonus::class);
```

The package fires events the same way regardless — only the *registration
mechanism* changes.

## Why this matters for forward compatibility

Because your platform code lives in listener classes you own, package
upgrades cannot break your hooks. Even if the package internally rewrites
how `finalizeRegistration()` works, as long as it still dispatches
`EmailVerified` at the same moment with the same payload contract, your
listeners keep working. The event class is the API.
