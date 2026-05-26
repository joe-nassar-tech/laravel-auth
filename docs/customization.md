# Customization Guide

Everything you can override in `joe-404/laravel-auth` without touching the package source.

---

## Table of Contents

1. [Extra Registration Fields](#1-extra-registration-fields)
2. [Custom Register Request (FormRequest subclass)](#2-custom-register-request)
3. [Extra-field Validation Messages](#3-extra-field-validation-messages)
4. [Extra-field Transformers](#4-extra-field-transformers)
5. [Referral Codes](#5-referral-codes)
6. [Custom Response Formatter](#6-custom-response-formatter)
7. [Custom OTP Channel (SMS, WhatsApp…)](#7-custom-otp-channel)
8. [Custom Email Templates](#8-custom-email-templates)
9. [Custom Response Messages](#9-custom-response-messages)
10. [Multi-language Support](#10-multi-language-support)
11. [Custom Referral Code Generator](#11-custom-referral-code-generator)
12. [Custom Phone Driver (v2.6)](#12-custom-phone-driver-v26)
13. [All Contracts (quick reference)](#13-all-contracts)

---

## 1. Extra Registration Fields

Add any field to the registration form — no controller changes required.

### How it works

Fields declared in `extra_fields_rules` are validated on `POST /auth/register`. Their validated values are held in cache and written to `User::create()` during `POST /auth/register/complete` (step 3).

> **Applies to social sign-in too (v2.6).** The same `extra_fields_rules` drive the OAuth path when `social.profile_completion.enabled` is true: a brand-new Google user is sent to `POST /auth/social/complete`, which validates these exact rules before creating the account. Required fields block; optional fields are validated only if submitted. So you declare your fields once and both registration paths enforce them. See `docs/configuration.md` → `social` and the v2.6 section of `docs/upgrading.md`.

### Simple example

```php
// config/auth_system.php
'registration' => [
    'extra_fields_rules' => [
        'username'       => 'required|string|min:3|max:30|unique:users,username',
        'date_of_birth'  => 'required|date|before:18 years ago',
        'agreed_terms'   => 'required|accepted',
        'agreed_18_plus' => 'required|accepted',
        'phone'          => 'nullable|string|max:20',
    ],
],
```

Rules can be a pipe-separated string (as above) or an array (required for object-based rules):

```php
'extra_fields_rules' => [
    'username' => ['required', 'string', 'min:3', Rule::unique('users', 'username')],
],
```

**You must also:**

1. Add the field to `User` model's `$fillable`:

```php
protected $fillable = [
    'name', 'email', 'password',
    'username', 'date_of_birth',  // ← add your fields here
];
```

2. Add a migration column:

```php
$table->string('username')->nullable()->unique();
$table->date('date_of_birth')->nullable();
```

Fields not in `$fillable` are silently ignored by `User::create()`.

---

## 2. Custom Register Request

For complex conditional rules, or when `extra_fields_rules` is not flexible enough, extend the package's `RegisterRequest`:

```php
// app/Http/Requests/MyRegisterRequest.php
<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;
use Joe404\LaravelAuth\Http\Requests\RegisterRequest;

class MyRegisterRequest extends RegisterRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'username' => ['required', 'string', 'min:3', Rule::unique('users')],
            'phone'    => ['required_if:country,LB', 'string', 'max:20'],
        ]);
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'username.unique'           => 'That username is already taken.',
            'phone.required_if'         => 'Phone number is required for users in Lebanon.',
        ]);
    }
}
```

Wire it in config:

```php
'registration' => [
    'request_class' => \App\Http\Requests\MyRegisterRequest::class,
],
```

`request_class` takes priority over `extra_fields_rules` — if both are set, the custom request class is used and `extra_fields_rules` is ignored.

---

## 3. Extra-field Validation Messages

Override validation error messages per field/rule without writing a custom request class.

```php
// config/auth_system.php
'registration' => [
    'extra_fields_rules' => [
        'username'       => 'required|string|min:3|alpha_dash',
        'date_of_birth'  => 'required|date|before:18 years ago',
        'agreed_terms'   => 'required|accepted',
        'agreed_18_plus' => 'required|accepted',
    ],
    'extra_fields_messages' => [
        'username.required'      => 'Please choose a username.',
        'username.min'           => 'Username must be at least 3 characters.',
        'username.alpha_dash'    => 'Usernames may only contain letters, numbers, dashes, and underscores.',
        'date_of_birth.required' => 'Please enter your date of birth.',
        'date_of_birth.before'   => 'You must be at least 18 years old to register.',
        'agreed_terms.accepted'  => 'You must accept our Terms of Service to continue.',
        'agreed_18_plus.accepted'=> 'You must confirm that you are 18 or older.',
    ],
],
```

Format: `"field.rule" => "message"`. Any key not listed falls back to Laravel's built-in message.

**Error response shape (HTTP 422):**

```json
{
  "success": false,
  "message": "The given data was invalid.",
  "errors": {
    "username": ["Please choose a username."],
    "agreed_terms": ["You must accept our Terms of Service to continue."]
  }
}
```

---

## 4. Extra-field Transformers

Derive or normalise a column value from the validated registration data — without writing a custom controller.

**Contract:**

```php
namespace Joe404\LaravelAuth\Contracts;

interface ExtraFieldTransformerContract
{
    public function transform(array $validated): mixed;
}
```

`$validated` contains all validated input: `email` plus every key from `extra_fields_rules`.

**Step 1 — Create the transformer class:**

```php
// app/Transformers/UsernameNormalizer.php
<?php

namespace App\Transformers;

use Joe404\LaravelAuth\Contracts\ExtraFieldTransformerContract;

final class UsernameNormalizer implements ExtraFieldTransformerContract
{
    public function transform(array $validated): mixed
    {
        return strtolower(trim((string) ($validated['username'] ?? '')));
    }
}
```

**Step 2 — Register it in config:**

The array key is the **target column name** where the result is written.

```php
'registration' => [
    'extra_fields_transformers' => [
        'username_normalized' => \App\Transformers\UsernameNormalizer::class,
    ],
],
```

**Step 3 — Add the target column to your migration and `$fillable`:**

```php
// Migration
$table->string('username_normalized')->nullable()->unique();

// User.php
protected $fillable = [..., 'username_normalized'];
```

**Another example — derive a `display_name` from `first_name` + `last_name`:**

```php
final class DisplayNameTransformer implements ExtraFieldTransformerContract
{
    public function transform(array $validated): mixed
    {
        $first = trim($validated['first_name'] ?? '');
        $last  = trim($validated['last_name'] ?? '');
        return trim("{$first} {$last}") ?: null;
    }
}
```

```php
'extra_fields_transformers' => [
    'display_name' => \App\Transformers\DisplayNameTransformer::class,
],
```

**Security:** transformers cannot bypass the privileged-field denylist. These target names are always stripped even if a transformer writes to them: `role`, `roles`, `is_admin`, `admin`, `email_verified_at`, `password`, `password_change_required`.

---

## 5. Referral Codes

Generate a unique referral code for every new user during `finalizeRegistration()`.

> **Looking for the full referral system** — fingerprint anti-abuse, reward handlers, redeem endpoint, admin override, frontend integration guide?
> See **[docs/referral-codes.md](referral-codes.md)** for the complete walkthrough. This section only covers the *generator* side.

### Enable in config

```php
'referral_code' => [
    'enabled'   => true,
    'column'    => 'referral_code',
    'length'    => 8,
    'uppercase' => true,
    'generator' => null,   // null = built-in random alphanumeric
],
```

### Required migration

```php
Schema::table('users', function (Blueprint $table): void {
    $table->string('referral_code', 20)->nullable()->unique();
});
```

Add `referral_code` to `User` model's `$fillable`.

### Will not overwrite

If the user already supplied a value for the referral column through `extra_fields_rules`, the package will not overwrite it.

### Custom generator

See [Custom Referral Code Generator](#11-custom-referral-code-generator) below.

---

## 6. Custom Response Formatter

Swap the JSON envelope to match your API conventions.

**The default envelope:**

```json
// success
{ "success": true, "message": "...", "data": {} }

// error
{ "success": false, "message": "...", "errors": {} }
```

**Contract:**

```php
namespace Joe404\LaravelAuth\Contracts;

interface ResponseFormatterContract
{
    public function format(bool $success, string $message, array $data, array $errors): array;
}
```

**Example — custom envelope:**

```php
// app/Auth/MyResponseFormatter.php
<?php

namespace App\Auth;

use Joe404\LaravelAuth\Contracts\ResponseFormatterContract;

final class MyResponseFormatter implements ResponseFormatterContract
{
    public function format(bool $success, string $message, array $data, array $errors): array
    {
        return [
            'ok'      => $success,
            'msg'     => $message,
            'payload' => $success ? $data : $errors,
        ];
    }
}
```

**Register via config (recommended):**

```php
// config/auth_system.php
'response' => [
    'formatter' => \App\Auth\MyResponseFormatter::class,
],
```

**Or via service container (config takes priority):**

```php
// app/Providers/AppServiceProvider.php
use Joe404\LaravelAuth\Contracts\ResponseFormatterContract;

public function register(): void
{
    $this->app->bind(ResponseFormatterContract::class, \App\Auth\MyResponseFormatter::class);
}
```

---

## 7. Custom OTP Channel

Replace the built-in email delivery with any channel — SMS, WhatsApp, push notification, etc.

### Single-delivery contract

Use when your channel sends either the OTP code or the magic link, but not both at once:

```php
namespace Joe404\LaravelAuth\Contracts;

interface OtpChannelContract
{
    public function send(string $recipient, string $code, string $type, array $context = []): void;
}
```

| Parameter | Description |
|---|---|
| `$recipient` | Email address (or phone number if your channel uses phone) |
| `$code` | The OTP digits (e.g. `"482910"`) for type=otp; the full URL for type=magic_link |
| `$type` | `email_verify`, `magic_link_verify`, `password_reset`, `magic_link_reset` |
| `$context` | Extra metadata (user ID, locale, etc.) |

**Example — Twilio SMS:**

```php
// app/Channels/SmsOtpChannel.php
<?php

namespace App\Channels;

use Joe404\LaravelAuth\Contracts\OtpChannelContract;
use Twilio\Rest\Client;

final class SmsOtpChannel implements OtpChannelContract
{
    public function __construct(private readonly Client $twilio) {}

    public function send(string $recipient, string $code, string $type, array $context = []): void
    {
        // For magic link types, $code contains the full URL
        $message = str_contains($type, 'magic_link')
            ? "Click to verify: {$code}"
            : "Your code: {$code}";

        $this->twilio->messages->create($recipient, [
            'from' => config('services.twilio.from'),
            'body' => $message,
        ]);
    }
}
```

### Combined-delivery contract

Use when your channel can send OTP code and magic link in a single message (e.g. a WhatsApp template that includes a button):

```php
namespace Joe404\LaravelAuth\Contracts;

interface CombinedOtpChannelContract extends OtpChannelContract
{
    public function sendCombined(string $recipient, string $code, string $url, string $type, array $context = []): void;
}
```

When `verification.method=both` and your channel implements `CombinedOtpChannelContract`, the package calls `sendCombined()` instead of calling `send()` twice. This lets you send a single message that contains both the code and the link.

```php
final class WhatsAppChannel implements CombinedOtpChannelContract
{
    public function send(string $recipient, string $code, string $type, array $context = []): void
    {
        // Fallback: code-only (called when type is not "both")
        WhatsApp::sendText($recipient, "Your code: {$code}");
    }

    public function sendCombined(string $recipient, string $code, string $url, string $type, array $context = []): void
    {
        // Single message with code + clickable link
        WhatsApp::sendTemplate($recipient, 'auth_verify', [
            'code' => $code,
            'url'  => $url,
        ]);
    }
}
```

**Register in config:**

```php
'otp_channel' => [
    'driver' => \App\Channels\SmsOtpChannel::class,
],
```

---

## 8. Custom Email Templates

Two options — pick one per email, or mix.

### Option A — Publish and edit Blade views (no PHP required)

```bash
php artisan vendor:publish --tag=auth-views
```

Edit files in `resources/views/vendor/laravel-auth/emails/`:

| Template | Email |
|---|---|
| `otp-verify.blade.php` | OTP code for registration |
| `otp-reset.blade.php` | OTP code for password reset |
| `magic-link-verify.blade.php` | Magic link for registration |
| `magic-link-reset.blade.php` | Magic link for password reset |
| `otp-verify-combined.blade.php` | OTP + link for registration (method=both) |
| `otp-reset-combined.blade.php` | OTP + link for password reset (method=both) |

Variables available in templates: `$code`, `$url` (magic link only), `$type`, `$user`.

### Option B — Custom Notification class (full control)

Point any `mail.*` config key to your own Notification class.

**Constructor signatures:**

- Single delivery: `__construct(string $code, string $type, array $context)`
- Combined delivery: `__construct(string $code, string $url, string $type, array $context)`

```php
// app/Notifications/MyVerificationEmail.php
<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

class MyVerificationEmail extends Notification
{
    public function __construct(
        private readonly string $code,
        private readonly string $type,
        private readonly array $context = [],
    ) {}

    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Verify your email — Acme')
            ->line("Your code is: **{$this->code}**")
            ->line('This code expires in 10 minutes.');
    }
}
```

```php
// config/auth_system.php
'mail' => [
    'otp_verify_notification' => \App\Notifications\MyVerificationEmail::class,
],
```

Option B takes priority over Option A for the same email slot. You can mix — override only the emails you care about and leave the rest using the built-in Blade template.

### Account lifecycle emails (v2.4)

Same pattern for account lifecycle notifications:

| Config key | Sent when |
|---|---|
| `account_deleted_notification` | User deletes their account |
| `account_restored_notification` | Account auto-restored on login during grace |
| `account_purged_notification` | Purge worker permanently anonymises the account |
| `account_status_changed_notification` | Admin changes the user's status |
| `account_deactivated_notification` | User deactivates their account |
| `account_reactivated_notification` | Account auto-reactivated on login |

Toggle individual emails without removing the class:

```php
'mail' => [
    'account_notifications_enabled' => [
        'deleted'        => true,
        'restored'       => true,
        'purged'         => false,
        'status_changed' => false,
        'deactivated'    => true,
        'reactivated'    => true,
    ],
],
```

---

## 9. Custom Response Messages

Override any success or error message with a static string.

```php
// config/auth_system.php
'messages' => [
    'register_initiated' => 'Almost there! Check your inbox for a verification code.',
    'register_complete'  => 'Welcome to Acme!',
    'login_success'      => 'Welcome back.',
    'logout_success'     => null,  // null = keep the built-in default
],

'errors' => [
    'invalid_credentials' => 'That email or password is incorrect.',
    'account_locked'      => 'Account locked. Try again in :seconds seconds.',
],
```

Setting a key to `null` or `''` re-enables the translation pipeline for that key. See [docs/localization.md](localization.md) for the full key list and multi-language support.

---

## 10. Multi-language Support

The package ships built-in English and Arabic translations. Every user-facing string goes through a three-step resolver:

1. `config('auth_system.messages.<key>')` / `config('auth_system.errors.<key>')` — wins if non-null
2. `trans('auth_system::messages.<key>')` / `trans('auth_system::errors.<key>')` — per-locale
3. Built-in English fallback

**Publish the language files:**

```bash
php artisan vendor:publish --tag=auth-lang
```

Files appear at `lang/vendor/auth_system/en/messages.php` and `errors.php`.

**Add a new locale (e.g. French):**

```
lang/vendor/auth_system/fr/messages.php
lang/vendor/auth_system/fr/errors.php
```

**Set locale per request:**

```php
// app/Http/Middleware/SetLocaleFromHeader.php
app()->setLocale($request->header('Accept-Language', 'en'));
```

See [docs/localization.md](localization.md) for the complete guide, full key list, and placeholders.

---

## 11. Custom Referral Code Generator

Replace the built-in random alphanumeric generator with your own.

**Contract:**

```php
namespace Joe404\LaravelAuth\Contracts;

interface ReferralCodeGeneratorContract
{
    public function generate(): string;
}
```

**Example — word-list style codes:**

```php
// app/Auth/HumanReferralGenerator.php
<?php

namespace App\Auth;

use Illuminate\Support\Str;
use Joe404\LaravelAuth\Contracts\ReferralCodeGeneratorContract;

final class HumanReferralGenerator implements ReferralCodeGeneratorContract
{
    public function generate(): string
    {
        // e.g. "BRAVE-WHALE-7423"
        return strtoupper(Str::slug(fake()->words(2, true)) . '-' . rand(1000, 9999));
    }
}
```

**Register in config:**

```php
'referral_code' => [
    'enabled'   => true,
    'generator' => \App\Auth\HumanReferralGenerator::class,
],
```

The package binds the generator to the container and resolves it via `app()->make($fqcn)`, so it supports constructor injection.

---

## 12. Custom Phone Driver (v2.6)

The phone verification + SMS-2FA system delivers codes through a **driver** chosen per channel (`sms`, `voice`, `whatsapp`). Built-in drivers: `log` (dev), `infobip`, `messagecentral`, `twilio`, `firebase`. To use any other provider (Vonage, Plivo, AWS SNS, an on-prem SMS gateway…), write a driver and register it.

### Step 1 — Implement the contract

```php
<?php

namespace App\Phone;

use Joe404\LaravelAuth\Contracts\PhoneDriverContract;
use Joe404\LaravelAuth\Exceptions\PhoneVerificationException;
use Illuminate\Support\Facades\Http;

class VonageDriver implements PhoneDriverContract
{
    /** @param array<string,mixed> $config  The provider's config block. */
    public function __construct(private readonly array $config) {}

    public function send(string $phone, string $code, string $channel, array $context = []): void
    {
        $response = Http::asForm()->post('https://rest.nexmo.com/sms/json', [
            'api_key'    => $this->config['api_key'] ?? '',
            'api_secret' => $this->config['api_secret'] ?? '',
            'to'         => ltrim($phone, '+'),
            'from'       => $this->config['from'] ?? 'MyApp',
            'text'       => "Your verification code is: {$code}",
        ]);

        // Throw PhoneVerificationException on failure so the channel's
        // fallback provider (if configured) takes over.
        if (! $response->successful()) {
            throw new PhoneVerificationException(
                "Vonage send failed: HTTP {$response->status()}",
                'phone_send_failed',
            );
        }
    }

    /** Channels this driver can deliver. A channel not listed here triggers a config error. */
    public function supports(): array
    {
        return ['sms'];   // add 'voice' / 'whatsapp' only if you implement them
    }

    public function name(): string
    {
        return 'vonage';
    }
}
```

### Step 2 — Register the provider in config

```php
// config/auth_system.php → phone.providers
'vonage' => [
    'driver'     => \App\Phone\VonageDriver::class,
    'api_key'    => env('VONAGE_API_KEY'),
    'api_secret' => env('VONAGE_API_SECRET'),
    'from'       => env('VONAGE_FROM', 'MyApp'),
],
```

The whole provider block is passed to your driver's constructor as `$config`, so any keys you add are available as `$this->config['…']`.

### Step 3 — Point a channel at it

```php
// config/auth_system.php → phone.channels
'sms' => ['provider' => 'vonage', 'fallback' => 'log'],
```

That's it — no service-provider code needed. The package's `PhoneDriverManager` resolves the driver class from the container (so you can type-hint dependencies in the constructor alongside `$config`).

### Alternative — register a closure at runtime

If you need to build the driver yourself (custom client, secrets manager, etc.), call `PhoneDriverManager::extend()` in your `AppServiceProvider::boot()`:

```php
use Joe404\LaravelAuth\Phone\PhoneDriverManager;

public function boot(): void
{
    $this->app->make(PhoneDriverManager::class)
        ->extend('vonage', function ($app, array $config) {
            return new \App\Phone\VonageDriver($config);
        });
}
```

A closure registered this way wins over the `driver` class in config for that provider key.

### How fallback works

When a channel declares a `fallback`, and the primary provider throws a `PhoneVerificationException`, the manager logs a warning and retries on the fallback provider. If the fallback also throws (or none is configured), the exception propagates and the send fails. A provider that does not list the requested channel in `supports()` triggers a `phone_channel_unsupported` error rather than a silent no-op.

> **Production note:** the default `log` driver writes plaintext codes to the Laravel log. The package emits a boot-time warning if `log` is active on `production`. Always point production channels at a real provider.

---

## 13. All Contracts (quick reference)

| Contract | Config key | What it overrides |
|---|---|---|
| `ResponseFormatterContract` | `response.formatter` | JSON response envelope |
| `OtpChannelContract` | `otp_channel.driver` | How OTP codes / magic links are delivered |
| `CombinedOtpChannelContract` | `otp_channel.driver` | Combined OTP + magic link delivery (single message) |
| `ExtraFieldTransformerContract` | `registration.extra_fields_transformers` | Derive/normalise fields after validation |
| `ReferralCodeGeneratorContract` | `referral_code.generator` | How referral codes are generated |
| `DeviceResolverContract` | (container binding) | How device name / browser / OS are resolved from the request |
| `PhoneDriverContract` *(v2.6)* | `phone.providers.<key>.driver` | How phone OTP codes are sent (SMS/voice/WhatsApp) |
| `TwoFactorMethodContract` *(v2.6)* | (internal) | Interface implemented by the built-in 2FA methods |

All contracts live under the `Joe404\LaravelAuth\Contracts\` namespace.

### `DeviceResolverContract`

Used by session tracking and new-device login notifications. Not exposed in config — bind it in your `AppServiceProvider`:

```php
use Joe404\LaravelAuth\Contracts\DeviceResolverContract;

$this->app->bind(DeviceResolverContract::class, \App\Auth\MyDeviceResolver::class);
```

**Contract:**

```php
interface DeviceResolverContract
{
    public function resolve(Request $request): array;
    // Must return: ['browser' => '...', 'os' => '...', 'device' => '...', 'platform' => '...']
}
```
