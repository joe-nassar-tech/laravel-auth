# Customization (v2.2+)

Four opt-in features let host apps tailor the registration response surface
without having to write a custom controller. All four default to off /
null, so existing apps see no behavioural change.

- [Referral codes](#1-referral-codes)
- [Custom response messages](#2-custom-response-messages)
- [Extra-field validation messages](#3-extra-field-validation-messages)
- [Extra-field transformers](#4-extra-field-transformers)

Want translations on top? See `docs/localization.md`.

---

## 1. Referral codes

Generate a unique referral code per user during `finalizeRegistration()`
and persist it on the configured column.

### Config

```php
// config/auth_system.php
'referral_code' => [
    'enabled'   => env('AUTH_REFERRAL_CODE_ENABLED', false),
    'column'    => env('AUTH_REFERRAL_CODE_COLUMN', 'referral_code'),
    'length'    => env('AUTH_REFERRAL_CODE_LENGTH', 8),
    'uppercase' => env('AUTH_REFERRAL_CODE_UPPERCASE', true),
    'generator' => env('AUTH_REFERRAL_CODE_GENERATOR', null),
],
```

### Migration

Add the column to your `users` table (or whichever column you set in
`referral_code.column`):

```php
Schema::table('users', function (Blueprint $table): void {
    $table->string('referral_code')->nullable()->unique();
});
```

Make sure the column is also in the model's `$fillable`.

### Behaviour

- Disabled (default): nothing happens.
- Enabled: `finalizeRegistration()` invokes the bound
  `ReferralCodeGeneratorContract` and writes the result to the user's
  configured column.
- If the host app *already* supplied a value via `extra_fields_rules`
  (e.g. the registering user pasted a code), the package will not
  overwrite it.

### Swapping the generator

The default generator (`DefaultReferralCodeGenerator`) makes random
alphanumeric codes of length `referral_code.length`. Replace it by
pointing the config at your own implementation of the contract:

```php
namespace App\Auth;

use Joe404\LaravelAuth\Contracts\ReferralCodeGeneratorContract;

final class HumanReferralGenerator implements ReferralCodeGeneratorContract
{
    public function generate(): string
    {
        // Word-list style: "BRAVE-WHALE-7423"
        return \strtoupper(\Str::slug(\fake()->words(2, true)) . '-' . \rand(1000, 9999));
    }
}
```

```php
'referral_code' => [
    'enabled'   => true,
    'generator' => \App\Auth\HumanReferralGenerator::class,
],
```

---

## 2. Custom response messages

Every controller success message is overridable per key.

```php
'messages' => [
    'register_initiated' => 'Check your inbox for a verification code.',
    'login_success'      => 'Welcome back!',
    'logout_success'     => null,  // keep built-in default
],
```

Leave a key as `null` (or omit it) to keep the built-in English default.
An empty string is treated the same as `null`.

The full list of keys lives in `docs/localization.md`. The same resolver
also reads `trans('auth_system::messages.*')`, so the same `messages`
block can sit alongside per-locale translation files. See the
[localization guide](localization.md) for details.

### Example: branded onboarding

```php
'messages' => [
    'register_initiated'  => 'Almost there! We sent a code to your inbox.',
    'register_verified'   => 'Email confirmed — pick a password to finish signing up.',
    'register_complete'   => 'Welcome to Acme Studios.',
    'verification_resent' => 'We resent your code. Check your inbox.',
],
```

---

## 3. Extra-field validation messages

Pair custom validation rules in `extra_fields_rules` with Laravel-style
per-rule messages, without having to subclass the request.

```php
'registration' => [
    'extra_fields_rules' => [
        'username'      => 'required|string|min:3|alpha_dash',
        'date_of_birth' => 'required|date|before:18 years ago',
    ],
    'extra_fields_messages' => [
        'username.required'      => 'Pick a username before you continue.',
        'username.alpha_dash'    => 'Usernames may only contain letters, numbers, dashes, and underscores.',
        'date_of_birth.before'   => 'You must be at least 18 years old to register.',
    ],
],
```

Validation errors come back in the standard 422 envelope:

```json
{
  "success": false,
  "message": "The given data was invalid.",
  "errors": {
    "username":      ["Pick a username before you continue."],
    "date_of_birth": ["You must be at least 18 years old to register."]
  }
}
```

---

## 4. Extra-field transformers

Map a *target* field name to a class that derives its value from the
validated input. Useful for canonicalization (`username` →
`username_normalized`), or auto-filling a denormalised column.

### Contract

```php
namespace App\Auth;

use Joe404\LaravelAuth\Contracts\ExtraFieldTransformerContract;

final class UsernameLowercaseTransformer implements ExtraFieldTransformerContract
{
    public function transform(array $validated): mixed
    {
        return \strtolower(\trim((string) ($validated['username'] ?? '')));
    }
}
```

### Wiring

```php
'registration' => [
    'extra_fields_rules' => [
        'username' => 'required|string|min:3',
    ],
    'extra_fields_transformers' => [
        'username_normalized' => \App\Auth\UsernameLowercaseTransformer::class,
    ],
],
```

The transformer runs after validation and before the user row is created,
so `username_normalized` lands in `User::create([...])` together with the
other fields. Don't forget to add `username_normalized` to the `$fillable`
array on your `User` model and to the migration.

### Security: privileged-field denylist still applies

Transformers cannot bypass the package's mass-assignment guard. The
following target field names are *always* stripped, even if a transformer
writes to them:

```
role, roles, is_admin, admin, email_verified_at, password, password_change_required
```

This is enforced by `AuthService::stripPrivilegedFields()`, which is run
twice during registration — once on the raw extra fields, once on the
post-transformer payload.

---

## See also

- [Localization guide](localization.md) — translating success and error
  messages, publishing language files, RTL support.
- [Configuration reference](configuration.md) — full `auth_system.php`
  key-by-key.
- [Upgrade guide](upgrading.md) — 1.x → 2.x breaking changes.
