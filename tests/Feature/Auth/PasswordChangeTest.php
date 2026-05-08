<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Joe404\LaravelAuth\Events\PasswordChanged;
use Joe404\LaravelAuth\Tests\Fixtures\User;

beforeEach(function (): void {
    \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'user', 'guard_name' => 'web']);

    $this->user = User::create([
        'name'              => 'Change User',
        'email'             => 'change@example.com',
        'password'          => bcrypt('OldPassword1!'),
        'email_verified_at' => now(),
        'is_active'         => true,
    ]);

    $tokenResult      = $this->user->createToken('primary-token');
    $this->plainToken = $tokenResult->plainTextToken;
    $this->token      = $tokenResult->accessToken;
});

it('can change password with correct current password', function (): void {
    $response = $this->withToken($this->plainToken)
        ->postJson('/auth/password/change', [
            'current_password'          => 'OldPassword1!',
            'new_password'              => 'NewPassword1!',
            'new_password_confirmation' => 'NewPassword1!',
        ]);

    $response->assertStatus(200)
        ->assertJson(['success' => true]);

    $this->user->refresh();
    expect(\Illuminate\Support\Facades\Hash::check('NewPassword1!', $this->user->password))->toBeTrue();
});

it('wrong current password returns 422', function (): void {
    $response = $this->withToken($this->plainToken)
        ->postJson('/auth/password/change', [
            'current_password'          => 'WrongPassword!',
            'new_password'              => 'NewPassword1!',
            'new_password_confirmation' => 'NewPassword1!',
        ]);

    $response->assertStatus(422)
        ->assertJson(['success' => false]);
});

it('new password same as current returns 422', function (): void {
    $response = $this->withToken($this->plainToken)
        ->postJson('/auth/password/change', [
            'current_password'          => 'OldPassword1!',
            'new_password'              => 'OldPassword1!',
            'new_password_confirmation' => 'OldPassword1!',
        ]);

    $response->assertStatus(422)
        ->assertJson(['success' => false]);
});

it('logout_all true revokes all other tokens but keeps current', function (): void {
    // Create a second token
    $this->user->createToken('second-token');

    expect($this->user->tokens()->count())->toBe(2);

    $response = $this->withToken($this->plainToken)
        ->postJson('/auth/password/change', [
            'current_password'          => 'OldPassword1!',
            'new_password'              => 'NewPassword1!',
            'new_password_confirmation' => 'NewPassword1!',
            'logout_all'                => true,
        ]);

    $response->assertStatus(200)
        ->assertJson(['success' => true]);

    // The second token should be deleted, current (primary) should remain
    expect($this->user->tokens()->count())->toBe(1);
    expect($this->user->tokens()->where('id', $this->token->id)->exists())->toBeTrue();
});

it('logout_all false keeps all sessions active', function (): void {
    // Create a second token
    $secondToken = $this->user->createToken('second-token')->accessToken;

    expect($this->user->tokens()->count())->toBe(2);

    $response = $this->withToken($this->plainToken)
        ->postJson('/auth/password/change', [
            'current_password'          => 'OldPassword1!',
            'new_password'              => 'NewPassword1!',
            'new_password_confirmation' => 'NewPassword1!',
            'logout_all'                => false,
        ]);

    $response->assertStatus(200)
        ->assertJson(['success' => true]);

    // Both tokens should still exist
    expect($this->user->tokens()->count())->toBe(2);
    expect($this->user->tokens()->where('id', $this->token->id)->exists())->toBeTrue();
    expect($this->user->tokens()->where('id', $secondToken->id)->exists())->toBeTrue();
});

it('PasswordChanged event is fired', function (): void {
    Event::fake();

    $this->withToken($this->plainToken)
        ->postJson('/auth/password/change', [
            'current_password'          => 'OldPassword1!',
            'new_password'              => 'NewPassword1!',
            'new_password_confirmation' => 'NewPassword1!',
        ])
        ->assertStatus(200);

    Event::assertDispatched(PasswordChanged::class);
});
