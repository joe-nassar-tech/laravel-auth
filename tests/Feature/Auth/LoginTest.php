<?php

declare(strict_types=1);

use Joe404\LaravelAuth\Tests\Fixtures\User;

beforeEach(function (): void {
    \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'user', 'guard_name' => 'web']);
});

it('returns a token when correct credentials are provided', function (): void {
    $user = User::create([
        'name'              => 'Login User',
        'email'             => 'login@example.com',
        'password'          => bcrypt('secret123'),
        'email_verified_at' => now(),
        'is_active'         => true,
    ]);

    $response = $this->postJson('/auth/login', [
        'email'    => 'login@example.com',
        'password' => 'secret123',
    ]);

    $response->assertStatus(200)
        ->assertJson(['success' => true])
        ->assertJsonStructure(['data' => ['user', 'token']]);

    expect($response->json('data.token'))->not->toBeNull();
});

it('returns 401 when wrong password is given', function (): void {
    User::create([
        'name'              => 'Bad Pass User',
        'email'             => 'badpass@example.com',
        'password'          => bcrypt('correct'),
        'email_verified_at' => now(),
        'is_active'         => true,
    ]);

    $response = $this->postJson('/auth/login', [
        'email'    => 'badpass@example.com',
        'password' => 'wrong',
    ]);

    $response->assertStatus(401)
        ->assertJson(['success' => false]);
});

it('returns 403 when the account is inactive', function (): void {
    User::create([
        'name'              => 'Inactive User',
        'email'             => 'inactive@example.com',
        'password'          => bcrypt('password'),
        'email_verified_at' => now(),
        'is_active'         => false,
    ]);

    $response = $this->postJson('/auth/login', [
        'email'    => 'inactive@example.com',
        'password' => 'password',
    ]);

    $response->assertStatus(403)
        ->assertJson(['success' => false, 'message' => 'This account has been deactivated.']);
});

it('returns 403 when the email is not verified', function (): void {
    User::create([
        'name'              => 'Unverified User',
        'email'             => 'unverified@example.com',
        'password'          => bcrypt('password'),
        'email_verified_at' => null,
        'is_active'         => true,
    ]);

    $response = $this->postJson('/auth/login', [
        'email'    => 'unverified@example.com',
        'password' => 'password',
    ]);

    $response->assertStatus(403)
        ->assertJson(['success' => false, 'message' => 'Email address is not verified.']);
});

it('updates last_login_at on successful login', function (): void {
    $user = User::create([
        'name'              => 'Timestamp User',
        'email'             => 'timestamp@example.com',
        'password'          => bcrypt('password'),
        'email_verified_at' => now(),
        'is_active'         => true,
        'last_login_at'     => null,
    ]);

    expect($user->last_login_at)->toBeNull();

    $this->postJson('/auth/login', [
        'email'    => 'timestamp@example.com',
        'password' => 'password',
    ])->assertStatus(200);

    $user->refresh();
    expect($user->last_login_at)->not->toBeNull();
});
