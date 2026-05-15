# Localization & Custom Messages

`joe-404/laravel-auth` ships every user-facing string — success messages
and error messages alike — through a three-step resolver, so you can
either pin a single message globally or serve a different translation
per request locale.

Both layers are **opt-in**. Out of the box, the package returns the same
English JSON it always has.

---

## How the resolver picks a string

Every controller success response goes through `$this->msg($key, $default)`;
every caught exception goes through `$this->err($e)` (which knows the
exception's own `errorKey()`). Both helpers walk the same three steps:

| Step | Source | Wins when |
|------|--------|-----------|
| 1 | `config('auth_system.messages.<key>')` for success, `config('auth_system.errors.<key>')` for errors | The value is a non-empty string |
| 2 | `trans('auth_system::messages.<key>')` / `trans('auth_system::errors.<key>')` | Step 1 was `null`/`''` *and* a translation file exists for `app()->getLocale()` |
| 3 | The hardcoded English fallback in the controller / exception | Steps 1 and 2 both miss |

This means a host app can:

- Override a single string in one language (config wins, no translation needed).
- Translate the entire response surface across N locales (publish lang files,
  set the request locale).
- Mix both — config-pin some keys for branding while letting other keys
  follow the locale.

---

## 1. Static override (single locale, no translation files)

Best for small apps or rebranding inside one language.

```php
// config/auth_system.php

'messages' => [
    'register_initiated' => 'Check your inbox for a verification code.',
    'login_success'      => 'Welcome back to Acme!',
],

'errors' => [
    'invalid_credentials' => 'That email and password do not match.',
    'account_locked'      => 'Too many attempts. Wait :seconds seconds.',
],
```

Setting a key to `null` (the default) re-enables the translation pipeline
for that key. An empty string `''` is treated the same as `null`.

Placeholders in error keys use Laravel's standard `:name` syntax. Known
placeholders today:

| Key | Placeholders |
|-----|--------------|
| `account_locked` | `:seconds` |
| `social_provider_disabled` | `:provider` |
| `social_authentication_failed` | `:provider` |
| `social_email_unverified` | `:provider` |

---

## 2. Multi-language (translation files)

### Step 1 — Publish the package's language files

```bash
php artisan vendor:publish --tag=auth-lang
```

This copies `resources/lang/en/{messages,errors,validation}.php` into your
app under the `auth_system` namespace. The publish target auto-detects
your Laravel skeleton:

- Laravel 9+: `lang/vendor/auth_system/en/...`
- Older Laravel: `resources/lang/vendor/auth_system/en/...`

### Step 2 — Add translations for each locale

Copy the published `en/` directory to your target locale code and translate
the values. For example, Arabic:

```
lang/vendor/auth_system/ar/messages.php
lang/vendor/auth_system/ar/errors.php
```

```php
// lang/vendor/auth_system/ar/messages.php
return [
    'register_initiated' => 'تم إرسال رمز التحقق. يرجى التحقق من بريدك الإلكتروني.',
    'login_success'      => 'تم تسجيل الدخول بنجاح.',
    // … the rest of the keys …
];
```

> The package already ships English (`en`) and a sample Arabic (`ar`)
> translation as a reference. You do **not** need to publish them to use
> them — the `loadTranslationsFrom()` call in the service provider makes
> them available automatically. You only need to publish if you want to
> *edit* a packaged translation, or to add a brand-new locale.

### Step 3 — Set the request locale

Whatever you already use to pick a locale per request (a header middleware,
session value, URL prefix, etc.) just works. The package reads
`app()->getLocale()` at the moment each response is built.

```php
// Example: an APIs-style middleware that honors Accept-Language
public function handle(Request $request, Closure $next): Response
{
    $locale = $request->header('Accept-Language', config('app.locale'));

    if (in_array($locale, ['en', 'ar', 'fr', 'es'], true)) {
        app()->setLocale($locale);
    }

    return $next($request);
}
```

---

## 3. Key reference

### Success message keys (`auth_system::messages.*`)

```
register_initiated, register_verified, register_complete, verification_resent,
login_success, me_retrieved, logout_success, logout_all_success,
password_reset_sent, password_reset_otp_ok, password_reset_link_ok,
password_reset_success, password_changed,
sessions_retrieved, session_terminated,
api_tokens_retrieved, api_token_created, api_token_updated, api_token_revoked
```

### Error message keys (`auth_system::errors.*`)

```
invalid_credentials, account_inactive, email_not_verified,
otp_invalid, otp_expired,
registration_session_expired, completion_token_invalid,
email_already_registered, magic_link_invalid,
reset_token_invalid, current_password_invalid,
refresh_token_invalid, refresh_token_revoked, refresh_token_reused,
refresh_token_expired,
api_token_invalid_format, api_token_invalid_encoding,
api_token_revoked, api_token_expired,
social_provider_disabled, social_authentication_failed,
social_email_unverified, social_link_token_invalid,
social_user_not_found,
session_not_found, account_locked,
unauthenticated
```

---

## 4. Validation messages for your own fields

Validation rules added via `auth_system.registration.extra_fields_rules` are
validated by the package's `RegisterRequest`. Customize their error text
either statically per key:

```php
// config/auth_system.php
'registration' => [
    'extra_fields_rules'    => ['username' => 'required|string|min:3'],
    'extra_fields_messages' => [
        'username.required' => 'Pick a username before you continue.',
    ],
],
```

…or in your app's own `lang/<locale>/validation.php` using the standard
Laravel `attributes` / per-rule layout. The host app's translation files
are always loaded alongside the package's, so the same locale switch that
localizes auth messages also localizes validation messages for your fields.

---

## 5. Worked example: bilingual API

```php
// app/Http/Middleware/SetLocaleFromHeader.php
final class SetLocaleFromHeader
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->header('Accept-Language', 'en');

        if (in_array($locale, ['en', 'ar'], true)) {
            app()->setLocale($locale);
        }

        return $next($request);
    }
}
```

```bash
# English request
curl -X POST http://api.local/auth/login \
     -H 'Accept-Language: en' \
     -d '{"email":"x@y.com","password":"wrong"}'
# {"success":false,"message":"Invalid credentials.","data":{},"errors":{}}

# Arabic request
curl -X POST http://api.local/auth/login \
     -H 'Accept-Language: ar' \
     -d '{"email":"x@y.com","password":"wrong"}'
# {"success":false,"message":"بيانات الاعتماد غير صحيحة.","data":{},"errors":{}}
```

No code change in your controllers. No new endpoints. Same package.
