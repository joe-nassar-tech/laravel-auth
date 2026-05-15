<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| joe-404/laravel-auth — Validation Messages (English)
|--------------------------------------------------------------------------
|
| Optional translation file for built-in request validation. Standard
| Laravel field/rule overrides win in this order:
|
|   1. config('auth_system.registration.extra_fields_messages') — per-key
|      static override (highest priority).
|   2. trans('auth_system::validation.<field>.<rule>') — per-locale.
|   3. Laravel's default validation message.
|
| Use this file to localize messages for the package's own fields (email,
| password, completion_token, otp, refresh_token, etc.). Extra fields you
| add via extra_fields_rules can be translated in your own app's
| lang/<locale>/validation.php.
|
*/

return [
    'email'    => [
        'required' => 'An email address is required.',
        'email'    => 'Please provide a valid email address.',
    ],
    'otp'      => [
        'required' => 'A verification code is required.',
        'digits'   => 'The verification code must be :digits digits.',
    ],
    'password' => [
        'required'  => 'A password is required.',
        'min'       => 'The password must be at least :min characters.',
        'confirmed' => 'The password confirmation does not match.',
    ],
    'completion_token' => [
        'required' => 'A completion token is required.',
    ],
    'refresh_token' => [
        'required' => 'A refresh token is required.',
    ],
];
