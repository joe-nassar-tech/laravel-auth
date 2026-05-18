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
12. [All Six Contracts (quick reference)](#12-all-six-contracts)

---

## 1. Extra Registration Fields

Add any field to the registration form — no controller changes required.

### How it works

Fields declared in `extra_fields_rules` are validated on `POST /auth/register`. Their validated values are held in cache and written to `User::create()` during `POST /auth/register/complete` (step 3).

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

## 12. All Six Contracts (quick reference)

| Contract | Config key | What it overrides |
|---|---|---|
| `ResponseFormatterContract` | `response.formatter` | JSON response envelope |
| `OtpChannelContract` | `otp_channel.driver` | How OTP codes / magic links are delivered |
| `CombinedOtpChannelContract` | `otp_channel.driver` | Combined OTP + magic link delivery (single message) |
| `ExtraFieldTransformerContract` | `registration.extra_fields_transformers` | Derive/normalise fields after validation |
| `ReferralCodeGeneratorContract` | `referral_code.generator` | How referral codes are generated |
| `DeviceResolverContract` | (container binding) | How device name / browser / OS are resolved from the request |

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
