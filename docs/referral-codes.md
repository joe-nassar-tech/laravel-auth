# Referral Codes

A complete referral system with built-in anti-abuse detection. The package generates a unique code per user, lets new users submit a referrer's code at registration (or up to N hours after), runs fingerprint-based abuse checks, and hands the validated referral to your reward logic.

This feature is **security-sensitive**. Read the [What is detected / what is NOT](#what-is-detected--what-is-not) section before launching — there are real-world bypass scenarios you should understand.

---

## Table of Contents

1. [Quick Overview](#1-quick-overview)
2. [Enabling the Feature](#2-enabling-the-feature)
3. [How a Referral Flows Through the System](#3-how-a-referral-flows-through-the-system)
4. [What is Detected / What is NOT](#4-what-is-detected--what-is-not)
5. [Anti-Abuse Configuration](#5-anti-abuse-configuration)
6. [Frontend Integration — Browser (Web + SPA)](#6-frontend-integration--browser-web--spa)
7. [Frontend Integration — Mobile (iOS + Android)](#7-frontend-integration--mobile-ios--android)
8. [Writing Your Reward Handler — Step by Step](#8-writing-your-reward-handler--step-by-step)
9. [Example Rewards](#9-example-rewards)
10. [Endpoints Reference](#10-endpoints-reference)
11. [Events Reference](#11-events-reference)
12. [Admin Override Workflow](#12-admin-override-workflow)
13. [Database Schema](#13-database-schema)
14. [Full Config Reference](#14-full-config-reference)
15. [Troubleshooting](#15-troubleshooting)

---

## 1. Quick Overview

```
User A registers ─────► gets referral_code "K9PF2LMX4A"
                                       │
                                       │ (User A shares code)
                                       ▼
User B registers with referral_code = "K9PF2LMX4A"
                                       │
                                       ▼
           ┌─── Package compares B's fingerprint vs A's ───┐
           │                                                │
           ▼                                                ▼
        Different device & IP                       Same device or IP
           │                                                │
           ▼                                                ▼
      status = valid                            status = blocked / suspicious
           │                                                │
           ▼                                                ▼
    reward_handler fires                     NO reward — fires
           │                                  SuspiciousReferralDetected event
           ▼
   ReferralRedeemed event
```

**Key properties:**

- Registration **never fails** because of a referral problem. The user always finishes signing up; only the referral relationship is rejected.
- Hard rules (not config-overridable): one code per account, cannot use own code, code must exist.
- Soft rules (config-driven): same-IP / same-device responses (`block`, `flag`, `ignore`).
- Reward logic is **your** code, not ours. The package fires events and calls a handler you point at; what "reward" means in your product is up to you.

---

## 2. Enabling the Feature

### Step 1 — Add the column to your users table

The package writes the generated referral code into a column on your users table. Add it via a migration in your app:

```php
Schema::table('users', function (Blueprint $table) {
    $table->string('referral_code', 32)->nullable()->unique()->after('email');
});
```

Make sure `referral_code` is in your `User` model's `$fillable`:

```php
protected $fillable = [
    'name', 'email', 'password', 'referral_code',
];
```

### Step 2 — Run the package migration

```bash
php artisan migrate
```

This creates the `referrals` table and adds `fingerprint_hash` to `auth_sessions_extended`.

### Step 3 — Enable in config

```env
AUTH_REFERRAL_CODE_ENABLED=true
```

Or in `config/auth_system.php`:

```php
'referral_code' => [
    'enabled' => true,
    // ...
],
```

After enabling, the package:

- Generates a code for every new user during `POST /auth/register/complete`
- Mounts these routes:
  - `POST /auth/referrals/redeem`
  - `GET /auth/referrals`
  - `GET /auth/referrals/stats`
  - `GET /auth/admin/referrals`
  - `PATCH /auth/admin/referrals/{id}`

---

## 3. How a Referral Flows Through the System

### Path A — Code submitted at registration

```
POST /auth/register
{
  "email": "bob@example.com",
  "referral_code": "K9PF2LMX4A"      ← optional
}
```

The code is held in cache alongside the rest of Bob's registration. After Bob verifies his email and sets a password (`/auth/register/complete`), the package:

1. Creates Bob's user row + assigns the default role
2. Generates Bob's *own* referral code
3. Looks up the owner of `K9PF2LMX4A` (User A)
4. Reads User A's most-recent session fingerprint
5. Reads Bob's current request fingerprint
6. Compares the two — same IP? same device hash?
7. Decides status (`valid` / `suspicious` / `blocked`)
8. Persists the `referrals` row
9. Fires `ReferralCreated`
10. If status is `valid` → calls your `reward_handler` → fires `ReferralRedeemed`
11. If status is `suspicious` / `blocked` → fires `SuspiciousReferralDetected`

The registration response includes a `referral_error` field if any of the hard rules tripped (code not found, self-referral, already redeemed). Registration itself still returns `201`.

### Path B — Code submitted after registration (redeem endpoint)

If Bob forgot to enter a code during registration, he can still submit one within the configured window (default: **2 hours from account creation**):

```
POST /auth/referrals/redeem    (authenticated)
{
  "referral_code": "K9PF2LMX4A"
}
```

The same abuse checks run. Outside the window the endpoint returns a clear error:

```json
{
  "success": false,
  "message": "Referral code can no longer be redeemed. The redemption window has passed."
}
```

---

## 4. What is Detected / What is NOT

This is the most important section in this doc. **Do not skip it.**

### ✅ Detected (referral will be flagged/blocked)

| Scenario | Why it's caught |
|---|---|
| User A uses their own code in **the same browser** | Hard rule: own code rejected |
| User A uses their own code with a **different email**, same browser, same device | Fingerprint hash matches (canvas/WebGL/screen) |
| Same device, different browser (e.g. Chrome → Firefox on same laptop) | Fingerprint hash matches (the JS snippet excludes browser strings on purpose) |
| Same device, same browser, **incognito mode** | Canvas/WebGL still come from the same GPU |
| Same device, **VPN** changing the IP | `on_same_device` rule still matches |
| Same home Wi-Fi, two different family members on different laptops | `on_same_ip` rule (default: `flag` — manual admin review) |
| Mobile app **deleted and reinstalled** on iOS with Keychain storage | `device_id` survives uninstall |
| Mobile app **deleted and reinstalled** on Android using `ANDROID_ID` | `ANDROID_ID` survives uninstall |
| User A logged into account on **two phones**, then logged out of one and used it to self-refer | Permanent device history table (see below) — the device is matched even though the session is gone |
| User A logged into account on Mobile A then Mobile B, then on Mobile B created a self-referral attempt | Both devices are in User A's permanent history — Mobile B is matched regardless of which one was last active |

### ❌ NOT Detected (we are explicit about this)

| Scenario | Why it's missed | Mitigation |
|---|---|---|
| Different physical device, different network, different email | No shared signal at all | Email/phone verification reduces volume; rate-limit registration |
| Factory reset of iOS/Android phone | New ANDROID_ID, new Keychain | Combine with phone verification |
| Same person on phone + laptop with mobile data | Different fingerprint, different IP | Limit reward to one per "household" via your own logic |
| User disables JavaScript in browser | No fingerprint hash sent — falls back to IP-only | The package documents this — you can choose to require the JS fingerprint via a custom check |
| Sophisticated attacker spoofing canvas/WebGL with a browser extension | The hash will differ | Out of scope for this package — use a commercial anti-fraud SDK if you need device-graph defenses |

**No fingerprint system can catch the last few rows.** If your reward is large enough to attract sophisticated abuse, add:

- Email + phone verification before reward is paid
- Manual admin review of all referrals over $X
- A waiting period (reward paid 7 days after referred user is active)
- Behavioural signals (referred user must complete a real action before reward fires)

### How the abuse check reads device history

The package keeps a **permanent device history table** (`auth_user_devices`) — one row per (user, device) — that is populated on every login and **never deleted on logout**.

When a new user submits a referral code, the abuse check asks:

> "Does the referrer have **any** historical device that matches this new user's fingerprint or IP?"

Not "their *last* device" — **any** device, ever. That closes this otherwise-clean bypass:

1. User A logs in on Mobile A (`AAA`) and Mobile B (`BBB`) — both phones become part of A's history.
2. User A logs out of Mobile B.
3. User A creates a second account on Mobile B and submits their own referral code from a different email.

Without the permanent history, the package would only see active sessions — Mobile B was just logged out, so no signal. With the history table, Mobile B is still there with `last_seen_at = yesterday`, the new account's fingerprint matches `BBB`, and the referral is blocked.

The user can see and manage this history at `GET /auth/devices` (see [section 11](#11-endpoints-reference)). The `DELETE /auth/devices/{id}` endpoint lets a user forget a device — at which point that device is no longer in their history and could be used to submit a new referral. This is a deliberate trade-off: the user owns their data.

---

## 5. Anti-Abuse Configuration

The package detects three signals independently:

| Signal | Default Action | What it means |
|---|---|---|
| `on_same_ip` | `flag` | New user has the same IP as the referrer (same Wi-Fi). Common false positives — same family, office, café. |
| `on_same_device` | `block` | Fingerprint hash matches the referrer's, but IP differs (VPN). Very strong abuse signal. |
| `on_same_ip_and_device` | `block` | Both match. Almost certainly the same person. |

Three actions:

- **`block`** → status set to `blocked`, no reward, `SuspiciousReferralDetected` fires
- **`flag`** → status set to `suspicious`, no reward, `SuspiciousReferralDetected` fires (you can admin-override later)
- **`ignore`** → status set to `valid`, reward fires normally

Override in `.env`:

```env
AUTH_REFERRAL_ABUSE_SAME_IP=flag
AUTH_REFERRAL_ABUSE_SAME_DEVICE=block
AUTH_REFERRAL_ABUSE_BOTH=block
```

### Tuning recommendations

- **Lenient** (most B2C apps with small rewards): `flag / flag / block`
- **Default** (most apps): `flag / block / block`
- **Strict** (apps with large rewards): `block / block / block`

---

## 6. Frontend Integration — Browser (Web + SPA)

For the device-level fingerprint to actually catch abuse, **your frontend must send the `X-Browser-Fingerprint` header** on registration and redeem requests.

### Drop-in JS snippet

This snippet collects device-level signals (NOT browser-specific ones), hashes them with SHA-256, and returns a stable hash. It produces the same hash across Chrome/Firefox/incognito on the same machine.

```javascript
// fingerprint.js
async function computeBrowserFingerprint() {
  const signals = [];

  // 1. Canvas — rendered by the GPU. Consistent across browsers on the same device.
  try {
    const canvas = document.createElement('canvas');
    const ctx = canvas.getContext('2d');
    ctx.textBaseline = 'top';
    ctx.font = '14px Arial';
    ctx.fillStyle = '#f60';
    ctx.fillRect(0, 0, 200, 50);
    ctx.fillStyle = '#069';
    ctx.fillText('fingerprint-canvas-anchor', 2, 2);
    signals.push(canvas.toDataURL());
  } catch (_) {}

  // 2. WebGL renderer — the actual GPU name string.
  try {
    const gl = document.createElement('canvas').getContext('webgl');
    const ext = gl.getExtension('WEBGL_debug_renderer_info');
    signals.push(gl.getParameter(ext.UNMASKED_RENDERER_WEBGL));
    signals.push(gl.getParameter(ext.UNMASKED_VENDOR_WEBGL));
  } catch (_) {}

  // 3. Screen + colour depth + pixel ratio.
  signals.push(`${screen.width}x${screen.height}x${screen.colorDepth}@${window.devicePixelRatio}`);

  // 4. Timezone.
  signals.push(Intl.DateTimeFormat().resolvedOptions().timeZone);

  // 5. Hardware.
  signals.push(navigator.hardwareConcurrency || '');
  signals.push(navigator.deviceMemory || '');
  signals.push(navigator.maxTouchPoints || 0);

  // 6. Audio context fingerprint.
  try {
    const AC = window.OfflineAudioContext || window.webkitOfflineAudioContext;
    const ctx = new AC(1, 5000, 44100);
    const osc = ctx.createOscillator();
    osc.type = 'triangle';
    osc.frequency.value = 10000;
    const comp = ctx.createDynamicsCompressor();
    osc.connect(comp).connect(ctx.destination);
    osc.start(0);
    const buf = await ctx.startRendering();
    signals.push(buf.getChannelData(0).slice(4500, 5000).reduce((a, b) => a + Math.abs(b), 0).toString());
  } catch (_) {}

  // SHA-256 hash of the concatenated signals.
  const enc = new TextEncoder().encode(signals.join('|'));
  const hash = await crypto.subtle.digest('SHA-256', enc);
  return Array.from(new Uint8Array(hash))
    .map(b => b.toString(16).padStart(2, '0'))
    .join('');
}

export { computeBrowserFingerprint };
```

### Sending the header

Compute once at app boot (cache the result) and attach it to every auth request:

```javascript
// axios example
import axios from 'axios';
import { computeBrowserFingerprint } from './fingerprint';

const fp = await computeBrowserFingerprint();

axios.defaults.headers.common['X-Browser-Fingerprint'] = fp;

// fetch example
const res = await fetch('/auth/register', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'X-Browser-Fingerprint': fp,
  },
  body: JSON.stringify({ email, referral_code: code }),
});
```

### What if the frontend doesn't send the header?

The package degrades to **IP-only matching**. Referrals from a frontend without the JS snippet still work, but you lose the device-level abuse signal. No errors, no warnings — silent degradation by design.

You can change the header name via config:

```env
AUTH_REFERRAL_FP_HEADER=X-My-Fingerprint
```

---

## 7. Frontend Integration — Mobile (iOS + Android)

Mobile apps already send the `X-Device-Info` JSON header. Add a `device_id` field to it:

```json
{
  "model": "SM-G991B",
  "platform": "android",
  "device_id": "550e8400-e29b-41d4-a716-446655440000"
}
```

### iOS — Keychain

Generate a UUID once, store it in the Keychain (NOT UserDefaults). Keychain entries **survive app uninstall** so a malicious user cannot delete + reinstall to get a fresh ID.

```swift
import Security
import Foundation

enum DeviceID {
    private static let service = "com.yourapp.deviceid"
    private static let account = "device-id"

    static func get() -> String {
        if let existing = read() { return existing }
        let new = UUID().uuidString
        save(new)
        return new
    }

    private static func read() -> String? {
        let query: [String: Any] = [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrService as String: service,
            kSecAttrAccount as String: account,
            kSecReturnData as String: true,
            kSecMatchLimit as String: kSecMatchLimitOne,
        ]
        var item: AnyObject?
        guard SecItemCopyMatching(query as CFDictionary, &item) == errSecSuccess,
              let data = item as? Data,
              let s = String(data: data, encoding: .utf8) else { return nil }
        return s
    }

    private static func save(_ value: String) {
        let query: [String: Any] = [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrService as String: service,
            kSecAttrAccount as String: account,
            kSecValueData as String: value.data(using: .utf8)!,
        ]
        SecItemAdd(query as CFDictionary, nil)
    }
}
```

### Android — ANDROID_ID

`Settings.Secure.ANDROID_ID` is a 64-bit hex string tied to the device + your app's signing key. It's stable across reinstalls and only changes on factory reset.

```kotlin
import android.content.Context
import android.provider.Settings

object DeviceId {
    fun get(context: Context): String =
        Settings.Secure.getString(context.contentResolver, Settings.Secure.ANDROID_ID)
}
```

Then in your network layer (Retrofit/Ktor/OkHttp):

```kotlin
val deviceInfo = mapOf(
    "model"     to Build.MODEL,
    "platform"  to "android",
    "device_id" to DeviceId.get(context),
)
request.addHeader("X-Device-Info", Gson().toJson(deviceInfo))
```

### What if device_id is missing?

Same as the browser case — the package degrades to IP-only matching. No error.

---

## 8. Writing Your Reward Handler — Step by Step

The package does not know what "reward" means in your app. You wire it up by implementing **one method** in **one class**.

### Step 1 — Create the handler class

`app/Auth/MyReferralReward.php`:

```php
<?php

declare(strict_types=1);

namespace App\Auth;

use Joe404\LaravelAuth\Contracts\ReferralRewardHandlerContract;
use Joe404\LaravelAuth\Models\Referral;

class MyReferralReward implements ReferralRewardHandlerContract
{
    public function handle(Referral $referral): void
    {
        $referrer = $referral->referrer;
        $referred = $referral->referred;

        // Your reward logic goes here.
        // (See examples below.)
    }
}
```

### Step 2 — Point the config at your class

```env
AUTH_REFERRAL_REWARD_HANDLER=App\Auth\MyReferralReward
```

That's it. The package will call `handle($referral)` exactly once, the moment the referral becomes `valid` — whether that happens at registration, via the redeem endpoint, or via an admin override.

### Step 3 — Understand the guarantees

When your handler runs:

- `$referral->status` is `valid`
- `$referral->redeemed_at` is **still null** (the package sets it to `now()` after your handler returns)
- `$referral->referrer` and `$referral->referred` are eager-loaded
- The transaction that created the referral has already committed — you're in your own context

### Step 4 — Failure handling

If your handler throws:

- The referral is rolled back to `pending`
- The exception is logged
- The exception bubbles up (you can catch it at a higher level, or let your queue retry)

This means if your reward depends on an external API (Stripe, mailer, etc.) that times out, the referral is left in a `pending` state. You can listen to `ReferralCreated` from a queued listener and retry from there:

```php
class RetryReferralReward implements ShouldQueue
{
    public function handle(ReferralCreated $event): void
    {
        if ($event->referral->status === 'pending') {
            app(\App\Auth\MyReferralReward::class)->handle($event->referral);
        }
    }
}
```

---

## 9. Example Rewards

### Example 1 — Credit referrer's wallet with $100

```php
class CreditWalletReward implements ReferralRewardHandlerContract
{
    public function __construct(
        private readonly WalletService $wallets,
    ) {}

    public function handle(Referral $referral): void
    {
        $this->wallets->credit(
            user:   $referral->referrer,
            amount: 100_00, // cents
            reason: "Referral reward — invited {$referral->referred->email}",
        );
    }
}
```

### Example 2 — Give referrer a free subscription month

```php
class FreeSubscriptionMonthReward implements ReferralRewardHandlerContract
{
    public function handle(Referral $referral): void
    {
        $subscription = $referral->referrer->subscription('default');

        if ($subscription === null || $subscription->ended()) {
            // Referrer has no active subscription — skip
            return;
        }

        $subscription->extendTrial(now()->addMonth());
    }
}
```

### Example 3 — Generate a discount coupon code

```php
class DiscountCouponReward implements ReferralRewardHandlerContract
{
    public function handle(Referral $referral): void
    {
        $coupon = Coupon::create([
            'user_id'        => $referral->referrer->id,
            'code'           => 'REF-' . strtoupper(Str::random(8)),
            'discount_pct'   => 20,
            'expires_at'     => now()->addDays(30),
            'usage_limit'    => 1,
            'source_referral_id' => $referral->id,
        ]);

        Mail::to($referral->referrer)->send(new ReferralCouponMail($coupon));
    }
}
```

### Example 4 — Event-only mode (no handler at all)

Leave `AUTH_REFERRAL_REWARD_HANDLER` unset. Wire up a listener:

```php
// app/Providers/EventServiceProvider.php
protected $listen = [
    \Joe404\LaravelAuth\Events\ReferralCreated::class => [
        \App\Listeners\HandleReferral::class,
    ],
];
```

```php
class HandleReferral implements ShouldQueue
{
    public function handle(ReferralCreated $event): void
    {
        if ($event->referral->status !== 'valid') return;

        // your reward logic, on a queue, decoupled from the request
    }
}
```

---

## 10. Endpoints Reference

| Method | URL | Auth | Purpose |
|---|---|---|---|
| `POST` | `/auth/register` | none | Optional `referral_code` field accepted |
| `POST` | `/auth/referrals/redeem` | sanctum | Submit a code after registration (within window) |
| `GET`  | `/auth/referrals` | sanctum | List user's own referrals + status |
| `GET`  | `/auth/referrals/stats` | sanctum | Aggregate counts for user |
| `GET`  | `/auth/admin/referrals` | sanctum + admin | Paginated all-referrals list |
| `PATCH` | `/auth/admin/referrals/{id}` | sanctum + admin | Override status + add note |
| `GET`  | `/auth/devices` | sanctum | List every device that has ever logged into the account |
| `DELETE` | `/auth/devices/{id}` | sanctum | Forget a device (removes it from history + revokes any active session on it) |

### POST /auth/referrals/redeem

```json
// request
{ "referral_code": "K9PF2LMX4A" }

// success — code accepted, status reflects abuse-check outcome
{ "success": true, "message": "Referral code submitted.", "data": { "status": "valid", "id": 42 } }

// silent fail — wrong client type per config (e.g. web hits a mobile-only setup)
{ "success": true, "message": "Referral code submitted.", "data": { "status": null } }

// hard fails (422)
{ "success": false, "message": "Referral code not found." }
{ "success": false, "message": "You cannot use your own referral code." }
{ "success": false, "message": "You have already redeemed a referral code." }
{ "success": false, "message": "Referral code can no longer be redeemed. The redemption window has passed." }
```

### GET /auth/referrals (paginated user-view)

```json
{
  "success": true,
  "message": "Referrals retrieved.",
  "data": {
    "referrals": [
      { "id": 42, "status": "valid", "redeemed_at": "...", "created_at": "...", "referred": { "id": 17 } }
    ]
  }
}
```

### PATCH /auth/admin/referrals/{id}

```json
// request
{ "status": "valid", "note": "Confirmed legitimate — two roommates" }

// response
{
  "success": true,
  "message": "Referral status updated.",
  "data": { "referral": { ...full record... } }
}
```

---

## 11. Events Reference

| Event | When | Use it for |
|---|---|---|
| `ReferralCreated` | Always, after the referral row is saved | Audit log, queued reward processing |
| `SuspiciousReferralDetected` | Only when status is `suspicious` or `blocked` | Alerts, Slack pings, admin email |
| `ReferralRedeemed` | After reward handler returns successfully | Post-reward analytics, gamification |

All three events carry the full `Referral` model with `referrer` and `referred` eager-loaded.

---

## 12. Admin Override Workflow

Two roommates both sign up — the second referral is auto-flagged (same IP). Your admin investigates and confirms it's legitimate.

```bash
curl -X PATCH https://api.yourapp.com/auth/admin/referrals/42 \
  -H "Authorization: Bearer <admin-token>" \
  -H "Content-Type: application/json" \
  -d '{"status": "valid", "note": "Confirmed legitimate — two roommates"}'
```

This:

1. Updates the referral row's status + admin_note
2. If status is `valid` and `redeemed_at` is still null, **runs your reward handler** (so the override path is consistent with the auto path)
3. Fires `ReferralRedeemed`

Admins can also move `valid` back to `blocked` if a previously-approved referral turns out to be abuse — but note that the reward handler does **not** run "in reverse." If you need to claw back rewards, do it in your own admin tooling.

---

## 13. Database Schema

```
referrals
├── id                    bigint PK
├── referrer_id           bigint    (User who owns the code)
├── referred_id           bigint    (User who used the code) — unique
├── referral_code         varchar(64)
├── status                enum('pending','valid','suspicious','blocked','expired')
├── referrer_fingerprint  varchar(191) nullable
├── referred_fingerprint  varchar(191) nullable
├── referrer_ip           varchar(45) nullable
├── referred_ip           varchar(45) nullable
├── ip_match              boolean
├── device_match          boolean
├── redeemed_at           timestamp nullable
├── admin_note            varchar(500) nullable
├── created_at            timestamp
└── updated_at            timestamp
```

```
auth_sessions_extended  (modified)
└── fingerprint_hash     varchar(191) nullable  ← NEW
```

---

## 14. Full Config Reference

```php
'referral_code' => [
    // Master switch — when false, no code is generated, no routes are
    // mounted, and the optional registration field is ignored.
    'enabled' => true,

    // Column on users table where the code is stored. Must exist + be
    // in the User model's $fillable.
    'column' => 'referral_code',

    // Generated code shape. Ignored when a custom 'generator' is set.
    'length'    => 10,
    'uppercase' => true,

    // FQCN of a custom generator implementing ReferralCodeGeneratorContract.
    'generator' => null,

    // FQCN of your reward handler. Null = event-only mode.
    'reward_handler' => \App\Auth\MyReferralReward::class,

    // How long after registration a user can call /referrals/redeem.
    'redeem_window_minutes' => 120,

    // Which client types can submit/redeem codes.
    // 'both' | 'web' | 'mobile'
    // Disallowed clients FAIL SILENTLY (200 success, nothing stored).
    'allowed_clients' => 'both',

    // Anti-abuse policy per signal.
    // Actions: 'block' | 'flag' | 'ignore'
    'abuse' => [
        'on_same_ip'            => 'flag',
        'on_same_device'        => 'block',
        'on_same_ip_and_device' => 'block',
    ],

    // Header name used by the frontend to send the device-level
    // fingerprint hash. Change if it collides with another header
    // in your stack.
    'browser_fingerprint_header' => 'X-Browser-Fingerprint',
],
```

---

## 15. Troubleshooting

### "Every legitimate referral is being flagged as suspicious"

Your frontend isn't sending `X-Browser-Fingerprint` — so the package only has IP to compare. Two users on the same office Wi-Fi look identical without the device hash.

Fix: implement the JS snippet from section 6.

### "My reward handler runs twice on the same referral"

It shouldn't, but check that you haven't *also* wired a listener on `ReferralCreated` that does the same work. The reward handler is called by the package; events are for *additional* side effects only.

### "Admins overriding a `blocked` referral to `valid` doesn't trigger the reward"

The reward handler only runs on transitions from non-valid → valid AND when `redeemed_at` is null. If `redeemed_at` is already set (from an earlier valid state), the override is just a status change.

### "Mobile users get flagged after a clean reinstall"

Check that your iOS app uses Keychain (not UserDefaults) and your Android app uses `ANDROID_ID` (not a UUID stored in SharedPreferences). UserDefaults and SharedPreferences are both wiped on uninstall.

### "I want to disable the redeem endpoint but keep auto-applied referrals at registration"

Set the window to 0:

```env
AUTH_REFERRAL_REDEEM_WINDOW=0
```

Every redeem call will return "window has passed" immediately. Registration-time application is unaffected.

### "Where do I see who hasn't been rewarded yet?"

Query the table directly:

```php
Referral::where('status', 'valid')->whereNull('redeemed_at')->get();
```

These are referrals where the abuse check passed but the reward handler hasn't fired yet (or failed).
