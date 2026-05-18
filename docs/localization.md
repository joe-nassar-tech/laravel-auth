# Localization & Custom Messages

Every user-facing string — success messages and error messages alike — flows through a three-step resolver. The package ships with built-in English and a sample Arabic translation. Out of the box, nothing changes; the package returns the same English JSON as always.

---

## Table of Contents

1. [How the resolver works](#1-how-the-resolver-works)
2. [Static override (one language, no files)](#2-static-override-one-language-no-files)
3. [Multi-language (translation files)](#3-multi-language-translation-files)
4. [Setting the locale per request](#4-setting-the-locale-per-request)
5. [All message keys](#5-all-message-keys)
6. [All error keys](#6-all-error-keys)
7. [Error key placeholders](#7-error-key-placeholders)
8. [Validation messages for extra fields](#8-validation-messages-for-extra-fields)
9. [Worked example — bilingual API](#9-worked-example--bilingual-api)

---

## 1. How the resolver works

Every success response calls `$this->msg($key, $default)`. Every error response calls `$this->err($exception)`. Both helpers walk the same three steps in order:

| Step | Source | Wins when |
|---|---|---|
| 1 | `config('auth_system.messages.<key>')` or `config('auth_system.errors.<key>')` | The config value is a non-empty string |
| 2 | `trans('auth_system::messages.<key>')` or `trans('auth_system::errors.<key>')` | Step 1 missed and a translation file exists for the current locale |
| 3 | The hardcoded English fallback in the controller or exception class | Steps 1 and 2 both missed |

A host app can:
- Override one string globally (set it in config, done)
- Translate everything across N locales (publish lang files, set locale per request)
- Mix both — config-pin some keys for branding while others follow the locale

---

## 2. Static override (one language, no files)

Best for small apps or rebranding within a single language.

```php
// config/auth_system.php

'messages' => [
    'register_initiated' => 'Check your inbox for a verification code.',
    'register_complete'  => 'Welcome to Acme!',
    'login_success'      => 'Welcome back.',
    'logout_success'     => null,   // null = use built-in default
],

'errors' => [
    'invalid_credentials' => 'That email or password is incorrect.',
    'account_locked'      => 'Too many attempts. Please wait :seconds seconds.',
    'otp_invalid'         => 'The code you entered is incorrect. Please try again.',
],
```

Setting a key to `null` (the default for every key) or `''` re-enables the translation pipeline for that key.

---

## 3. Multi-language (translation files)

### Step 1 — Publish the language files

```bash
php artisan vendor:publish --tag=auth-lang
```

Files are copied to:
- Laravel 9+: `lang/vendor/auth_system/en/`
- Older Laravel: `resources/lang/vendor/auth_system/en/`

Three files are published: `messages.php`, `errors.php`, `validation.php`.

You only need to publish to **edit** a packaged translation or add a new locale. The built-in English and Arabic translations are available automatically without publishing (the service provider loads them via `loadTranslationsFrom()`).

### Step 2 — Add a new locale

Copy the published `en/` directory to your target locale code and translate the values.

**Example — French:**

```
lang/vendor/auth_system/fr/messages.php
lang/vendor/auth_system/fr/errors.php
```

```php
// lang/vendor/auth_system/fr/messages.php
return [
    'register_initiated'     => 'Vérification envoyée. Veuillez vérifier vos e-mails.',
    'register_verified'      => 'E-mail confirmé. Veuillez définir votre mot de passe.',
    'register_complete'      => 'Inscription terminée.',
    'verification_resent'    => 'E-mail de vérification renvoyé.',
    'login_success'          => 'Connexion réussie.',
    'me_retrieved'           => 'Utilisateur récupéré.',
    'logout_success'         => 'Déconnecté avec succès.',
    'logout_all_success'     => 'Déconnecté de tous les appareils.',
    'password_reset_sent'    => 'Instructions de réinitialisation envoyées.',
    'password_reset_otp_ok'  => 'Code vérifié. Soumettez votre nouveau mot de passe.',
    'password_reset_link_ok' => 'Lien validé. Soumettez votre nouveau mot de passe.',
    'password_reset_success' => 'Mot de passe réinitialisé. Vous êtes maintenant connecté.',
    'password_changed'       => 'Mot de passe modifié avec succès.',
    'sessions_retrieved'     => 'Sessions récupérées.',
    'session_terminated'     => 'Session terminée.',
    'api_tokens_retrieved'   => 'Tokens récupérés.',
    'api_token_created'      => 'Token créé.',
    'api_token_updated'      => 'Token mis à jour.',
    'api_token_revoked'      => 'Token révoqué.',
    'account_deleted'        => 'Compte supprimé.',
    'account_restored'       => 'Compte restauré.',
    'account_status_updated' => 'Statut du compte mis à jour.',
    'account_deactivated'    => 'Compte désactivé.',
    'account_reactivated'    => 'Compte réactivé.',
];
```

```php
// lang/vendor/auth_system/fr/errors.php
return [
    'invalid_credentials'           => 'Identifiants invalides.',
    'account_inactive'              => 'Ce compte est inactif.',
    'email_not_verified'            => 'Veuillez vérifier votre adresse e-mail.',
    'otp_invalid'                   => 'Code incorrect.',
    'otp_expired'                   => 'Ce code a expiré. Demandez-en un nouveau.',
    'completion_token_invalid'      => 'Lien d\'inscription invalide ou expiré.',
    'registration_session_expired'  => 'Session d\'inscription expirée. Recommencez.',
    'email_already_registered'      => 'Cet e-mail est déjà utilisé.',
    'reset_token_invalid'           => 'Token de réinitialisation invalide ou expiré.',
    'current_password_invalid'      => 'Mot de passe actuel incorrect.',
    'refresh_token_invalid'         => 'Token de rafraîchissement invalide.',
    'refresh_token_revoked'         => 'Token de rafraîchissement révoqué.',
    'refresh_token_reused'          => 'Token de rafraîchissement déjà utilisé.',
    'refresh_token_expired'         => 'Token de rafraîchissement expiré. Reconnectez-vous.',
    'api_token_invalid_format'      => 'Format de token invalide.',
    'api_token_invalid_encoding'    => 'Encodage de token invalide.',
    'api_token_revoked'             => 'Token révoqué.',
    'api_token_expired'             => 'Token expiré.',
    'social_provider_disabled'      => 'La connexion via :provider est désactivée.',
    'social_authentication_failed'  => 'Échec de l\'authentification :provider.',
    'social_email_unverified'       => ':provider n\'a pas fourni d\'e-mail vérifié.',
    'social_link_token_invalid'     => 'Lien de liaison de compte invalide.',
    'social_user_not_found'         => 'Utilisateur social introuvable.',
    'session_not_found'             => 'Session introuvable.',
    'account_locked'                => 'Compte verrouillé. Réessayez dans :seconds secondes.',
    'unauthenticated'               => 'Non authentifié.',
    'account_disabled'              => 'Ce compte a été désactivé.',
    'account_suspended'             => 'Ce compte est suspendu.',
    'account_deletion_disabled'     => 'La suppression de compte est désactivée.',
    'account_deactivation_disabled' => 'La désactivation de compte est désactivée.',
    'account_status_invalid'        => 'Statut de compte invalide.',
    'account_password_mismatch'     => 'Mot de passe incorrect.',
];
```

---

## 4. Setting the locale per request

The package reads `app()->getLocale()` at the moment each response is built. Whatever mechanism your app already uses to set the locale will work.

**Common pattern — `Accept-Language` header middleware:**

```php
// app/Http/Middleware/SetLocaleFromHeader.php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class SetLocaleFromHeader
{
    private const SUPPORTED = ['en', 'ar', 'fr', 'es'];

    public function handle(Request $request, Closure $next): mixed
    {
        $locale = $request->header('Accept-Language', config('app.locale'));

        if (in_array($locale, self::SUPPORTED, true)) {
            app()->setLocale($locale);
        }

        return $next($request);
    }
}
```

Register it globally in `bootstrap/app.php`:

```php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->append(\App\Http\Middleware\SetLocaleFromHeader::class);
})
```

Other valid approaches: a URL prefix (`/en/api`, `/ar/api`), a session value, a per-user `language` column read at login.

---

## 5. All message keys

Success response messages (`auth_system::messages.*`):

| Key | Default English |
|---|---|
| `register_initiated` | Verification sent. Please check your email. |
| `register_verified` | Email verified. Please set your password. |
| `register_complete` | Registration complete. |
| `verification_resent` | Verification email resent. |
| `login_success` | Login successful. |
| `me_retrieved` | User retrieved. |
| `logout_success` | Logged out successfully. |
| `logout_all_success` | Logged out from all devices. |
| `password_reset_sent` | Password reset instructions sent. |
| `password_reset_otp_ok` | OTP verified. Submit your new password using the reset_token. |
| `password_reset_link_ok` | Link validated. Submit your new password using the reset_token. |
| `password_reset_success` | Password reset successfully. You are now logged in. |
| `password_changed` | Password changed successfully. |
| `sessions_retrieved` | Sessions retrieved. |
| `session_terminated` | Session terminated. |
| `api_tokens_retrieved` | API tokens retrieved. |
| `api_token_created` | API token created. |
| `api_token_updated` | API token updated. |
| `api_token_revoked` | API token revoked. |
| `account_deleted` | Account scheduled for deletion. |
| `account_restored` | Account restored. |
| `account_status_updated` | Account status updated. |
| `account_deactivated` | Account deactivated. |
| `account_reactivated` | Account reactivated. |

---

## 6. All error keys

Error messages (`auth_system::errors.*`):

| Key | Default English |
|---|---|
| `invalid_credentials` | Invalid credentials. |
| `account_inactive` | This account is inactive. |
| `email_not_verified` | Please verify your email address before logging in. |
| `otp_invalid` | The OTP code is invalid. |
| `otp_expired` | The OTP code has expired. Please request a new one. |
| `completion_token_invalid` | The completion token is invalid or has expired. |
| `registration_session_expired` | Registration session expired. Please start again. |
| `email_already_registered` | This email address is already registered. |
| `reset_token_invalid` | The reset token is invalid or has expired. |
| `current_password_invalid` | The current password is incorrect. |
| `refresh_token_invalid` | The refresh token is invalid. |
| `refresh_token_revoked` | The refresh token has been revoked. |
| `refresh_token_reused` | The refresh token has already been used. |
| `refresh_token_expired` | The refresh token has expired. Please log in again. |
| `api_token_invalid_format` | Invalid API token format. |
| `api_token_invalid_encoding` | Invalid API token encoding. |
| `api_token_revoked` | This API token has been revoked. |
| `api_token_expired` | This API token has expired. |
| `social_provider_disabled` | Authentication via :provider is not enabled. |
| `social_authentication_failed` | Authentication via :provider failed. |
| `social_email_unverified` | :provider did not provide a verified email address. |
| `social_link_token_invalid` | The account link token is invalid or has expired. |
| `social_user_not_found` | Social user not found. |
| `session_not_found` | Session not found. |
| `account_locked` | Too many failed attempts. Try again in :seconds seconds. |
| `unauthenticated` | Unauthenticated. |
| `account_disabled` | This account has been disabled. |
| `account_suspended` | This account has been suspended. |
| `account_deletion_disabled` | Account deletion is not enabled. |
| `account_deactivation_disabled` | Account deactivation is not enabled. |
| `account_status_invalid` | Invalid account status. |
| `account_password_mismatch` | The password you entered is incorrect. |

---

## 7. Error key placeholders

Some error messages support `:placeholder` substitution (standard Laravel format):

| Key | Placeholder | Example |
|---|---|---|
| `account_locked` | `:seconds` — seconds remaining until the lockout lifts | `Try again in 47 seconds.` |
| `social_provider_disabled` | `:provider` — the OAuth provider name | `Authentication via Google is not enabled.` |
| `social_authentication_failed` | `:provider` | `Authentication via Google failed.` |
| `social_email_unverified` | `:provider` | `Google did not provide a verified email address.` |

Custom status error keys follow the pattern `account_{status}`. For example, a custom status `pending_review` expects the key `account_pending_review`.

---

## 8. Validation messages for extra fields

Validation rules added via `registration.extra_fields_rules` are validated by the package's `RegisterRequest`. Error messages can be customised two ways:

**Option A — `extra_fields_messages` in config** (no files needed):

```php
'registration' => [
    'extra_fields_rules'    => ['username' => 'required|string|min:3'],
    'extra_fields_messages' => [
        'username.required' => 'Pick a username to continue.',
        'username.min'      => 'Username must be at least 3 characters.',
    ],
],
```

**Option B — host app's own translation files:**

Any `lang/<locale>/validation.php` in your host app is loaded alongside the package's files. The `attributes` section lets you give friendly names to field labels:

```php
// lang/en/validation.php
return [
    'attributes' => [
        'date_of_birth' => 'date of birth',
        'agreed_terms'  => 'terms of service',
    ],
];
```

---

## 9. Worked example — bilingual API

Middleware that reads the `Accept-Language` header and sets the locale:

```php
// app/Http/Middleware/SetLocaleFromHeader.php
app()->setLocale(
    in_array($request->header('Accept-Language', 'en'), ['en', 'ar', 'fr'], true)
        ? $request->header('Accept-Language')
        : 'en'
);
```

Results with the same wrong credentials:

```bash
# English
curl -X POST /auth/login -H 'Accept-Language: en' -d '{"email":"x@y.com","password":"wrong"}'
# {"success":false,"message":"Invalid credentials.","data":{},"errors":{}}

# Arabic (ships with the package)
curl -X POST /auth/login -H 'Accept-Language: ar' -d '{"email":"x@y.com","password":"wrong"}'
# {"success":false,"message":"بيانات الاعتماد غير صحيحة.","data":{},"errors":{}}

# French (after adding lang files from step 2)
curl -X POST /auth/login -H 'Accept-Language: fr' -d '{"email":"x@y.com","password":"wrong"}'
# {"success":false,"message":"Identifiants invalides.","data":{},"errors":{}}
```

No changes to controllers, routes, or the package. One middleware. Same endpoints.
