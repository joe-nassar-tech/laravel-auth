<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Notification::fake();
    Queue::fake();

    \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'user', 'guard_name' => 'web']);
});

it('translates the register_initiated success message to the active locale', function (): void {
    Lang::addLines(
        ['messages.register_initiated' => 'Vérification envoyée.'],
        'fr',
        'auth_system',
    );

    app()->setLocale('fr');

    $response = $this->postJson('/auth/register', ['email' => 'fr@example.com'])->assertStatus(201);

    expect($response->json('message'))->toBe('Vérification envoyée.');
});

it('translates the invalid_credentials error to the active locale', function (): void {
    $this->createUser(['email' => 'login-i18n@example.com', 'password' => bcrypt('Password123!')]);

    Lang::addLines(
        ['errors.invalid_credentials' => 'Identifiants invalides.'],
        'fr',
        'auth_system',
    );

    app()->setLocale('fr');

    $response = $this->postJson('/auth/login', [
        'email'    => 'login-i18n@example.com',
        'password' => 'WrongPassword!',
    ])->assertStatus(401);

    expect($response->json('message'))->toBe('Identifiants invalides.');
});

it('keeps the static config override winning over translation', function (): void {
    Lang::addLines(
        ['messages.login_success' => 'Welcome [fr].'],
        'fr',
        'auth_system',
    );

    config()->set('auth_system.messages.login_success', 'CONFIG WINS');

    $this->createUser(['email' => 'override@example.com', 'password' => bcrypt('Password123!')]);

    app()->setLocale('fr');

    $response = $this->postJson('/auth/login', [
        'email'    => 'override@example.com',
        'password' => 'Password123!',
    ])->assertOk();

    expect($response->json('message'))->toBe('CONFIG WINS');
});

it('interpolates :provider placeholder in translated social error', function (): void {
    Lang::addLines(
        ['errors.social_provider_disabled' => ':provider غير مفعّل.'],
        'ar',
        'auth_system',
    );

    config()->set('auth_system.social.google.enabled', false);

    app()->setLocale('ar');

    $response = $this->getJson('/auth/social/google/redirect')->assertStatus(403);

    expect($response->json('message'))->toBe('Google غير مفعّل.');
});

it('falls back to the built-in English default when no translation exists', function (): void {
    app()->setLocale('xx-unknown');

    $this->createUser(['email' => 'fb@example.com', 'password' => bcrypt('Password123!')]);

    $response = $this->postJson('/auth/login', [
        'email'    => 'fb@example.com',
        'password' => 'wrongpass',
    ])->assertStatus(401);

    expect($response->json('message'))->toBe('Invalid credentials.');
});
