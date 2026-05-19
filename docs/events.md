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
9. [SPA / frontend integration — cross-tab verification handoff](#9-spa--frontend-integration--cross-tab-verification-handoff)

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
| `RegistrationEmailVerified` | After OTP / magic link verification, BEFORE the user row exists. Broadcasts on `private-auth.verification.{tempToken}` (when Reverb is enabled) so SPA tabs can drive cross-tab handoff. | `$tempToken`, `$completionToken`, `$email` |
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

// RegistrationEmailVerified
$event->tempToken        // string — the UUID returned from POST /auth/register
$event->completionToken  // string — the UUID the SPA must send to /auth/register/complete
$event->email            // string — the verified address

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

---

## 9. SPA / frontend integration — cross-tab verification handoff

This section is for the **frontend developer** integrating the registration flow into a SPA. It is the part of the lifecycle most likely to be implemented wrong, because it crosses tabs / devices / browser contexts. The notes below capture the mistakes that were actually made building the reference integration; they apply to any framework (React, Vue, Svelte) — only the syntax changes.

### The two-tab problem

The registration flow has three steps:

1. `POST /auth/register` — collect non-password fields, return `temp_token`
2. **Verify email** — either `POST /auth/register/verify-otp` or click a magic link
3. `POST /auth/register/complete` — submit `completion_token` + password

The password is collected at step 3, **never sent to the backend at step 1**. This is deliberate: a stranger should not be able to set a password on an email address they do not own. (See the [security rationale in `AuthService::initiateRegistration()`](../src/Services/AuthService.php).)

The mistake teams make: storing the password in `sessionStorage` at step 1 so step 3 can read it. That works **only when verification happens in the same tab**. The moment the user clicks the magic link in Gmail, on their phone, or in an incognito window, `sessionStorage` is empty and the flow dead-ends with "session expired".

### The recommended UX

Have the frontend render two views:

- **Tab A** (the one where the user submitted the register form) — shows "we sent a code, enter it below or click the link in your email." Waits for either OTP entry **or** a real-time event from the backend.
- **Tab B** (whatever opened the magic link — different tab, browser, or device) — after verification, shows "Email verified. Continue setting up your account in your original tab." Does **not** ask for a password. Does **not** navigate further.

When verification succeeds in Tab B, the backend fires `RegistrationEmailVerified`. The event broadcasts on `private-auth.verification.{tempToken}` and includes the `completion_token` in the payload. Tab A is subscribed to that channel (it knows `tempToken` from step 1), receives the broadcast, stores the `completion_token`, and moves itself to the "set your password" view.

Tab A then collects password + confirmation and calls `POST /auth/register/complete`. Done.

### Subscribing — Echo example

```ts
import Echo from 'laravel-echo'
import Pusher from 'pusher-js'

const echo = new Echo({
  broadcaster: 'reverb',
  key: import.meta.env.VITE_REVERB_APP_KEY,
  // ... your Reverb host/port/scheme settings
  client: new Pusher(import.meta.env.VITE_REVERB_APP_KEY, { /* ... */ }),
})

echo
  .private(`auth.verification.${tempToken}`)
  .listen('.RegistrationEmailVerified', (payload: {
    verified: boolean
    completion_token: string
    email: string
  }) => {
    // Store payload.completion_token, navigate to your "set password" screen.
  })
```

Notes:

- The Echo event name **must be prefixed with a dot**: `'.RegistrationEmailVerified'`. Without the dot, Echo prepends the Laravel namespace and your listener never fires.
- `Broadcast::channel('auth.verification.{tempToken}', ...)` (installed by `php artisan auth:install`) authorises subscribers without a logged-in user, by checking that the `tempToken` exists in `auth_otp_codes`. Do not delete that closure.
- `/broadcasting/auth` must be reachable by the unauthenticated registering tab — make sure your Sanctum stateful domains and any auth middleware on the broadcast route do not block it.

### Pitfalls that bit the reference implementation

These are real bugs found and fixed during development. Avoid repeating them.

**1. React StrictMode double-fires `useEffect` (and your magic link consumes itself).**

In development, React 18+ `StrictMode` mounts → unmounts → remounts every component. An effect that calls `GET /auth/register/verify-magic/{token}` on mount runs **twice** in dev — the first call validates and sets `used_at`; the second hits the now-used record and returns 422. The first response succeeds in the background, but the UI shows the second response's error.

Fix: guard the effect with a `useRef` (refs persist across the StrictMode unmount/remount cycle for function components):

```ts
const verifyAttempted = useRef(false)

useEffect(() => {
  const token = new URLSearchParams(window.location.search).get('token')
  if (!token) return
  if (verifyAttempted.current) return
  verifyAttempted.current = true
  // ... call verify-magic
}, [])
```

The symptom is "magic link always returns 422" — only in development, never in production (where StrictMode is a no-op). Easy to misdiagnose as a backend bug.

**2. Don't subscribe to the channel keyed on `completion_token` from Tab A.**

`completion_token` is the *output* of verification — Tab A does not have it yet. Subscribe with `tempToken`, which Tab A has had since step 1. The package broadcasts on `auth.verification.{tempToken}` precisely so Tab A can listen before verification happens.

**3. Don't store the password in `localStorage`.**

It "fixes" the cross-tab problem but at the cost of putting a plaintext password on disk for an unbounded time. The cross-tab handoff via broadcast removes the need entirely. (`sessionStorage` is also wrong, but at least it dies with the tab.)

**4. Don't navigate Tab B forward after verification.**

Once Tab B has consumed the `completion_token` (by hitting verify-magic), advancing it to a "set password" screen creates two tabs racing to call `/auth/register/complete` — and the cache entry is consumed by whichever lands first, leaving the other tab orphaned. Make Tab B a terminal screen ("verified, switch tabs"). Tab A is the one that finishes registration.

**5. Resend invalidates previous OTPs and magic links — by design.**

`POST /auth/email/resend-verification` calls `OtpService::invalidatePrevious()` before sending a new code. After resend, only the **latest** email's OTP / link works; clicking an old link returns 422. If your UI shows "resend email" without making this obvious, users will keep clicking the original email and report it as broken. Either make the latest-link rule explicit in the resend success message, or hide the resend button after the first send.

**6. Echo re-subscribes on every render if your callback identity changes.**

If you pass an inline arrow function as your `onVerified` handler and include it in the effect's deps, every re-render produces a new closure → effect runs cleanup + re-subscribe → `/broadcasting/auth` POST fires on a loop. Any state update in the parent (e.g. a 1-second countdown timer) triggers this. Capture the callback in a ref and leave it out of the deps:

```ts
const onVerifiedRef = useRef(onVerified)
useEffect(() => { onVerifiedRef.current = onVerified })

useEffect(() => {
  const channel = echo.private(`auth.verification.${tempToken}`)
  channel.listen('.RegistrationEmailVerified', (payload) => onVerifiedRef.current(payload))
  return () => { channel.stopListening('.RegistrationEmailVerified'); echo.leave(channel.name) }
}, [tempToken])  // NOT [tempToken, onVerified]
```

The symptom is a burst of `OPTIONS/POST /broadcasting/auth` requests in the network tab. Easy to mistake for a CORS or auth bug — actually a hook deps issue.

