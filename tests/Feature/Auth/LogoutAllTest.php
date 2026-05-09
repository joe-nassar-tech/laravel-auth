<?php

declare(strict_types=1);

use Joe404\LaravelAuth\Models\AuthSessionExtended;
use Joe404\LaravelAuth\Tests\Fixtures\User;

it('logoutAll preserves the calling token so the caller does not 401 on the response', function (): void {
    /** @var User $user */
    $user = User::create([
        'name' => 'la', 'email' => 'la@example.com',
        'password' => bcrypt('pw'), 'email_verified_at' => now(), 'is_active' => true,
    ]);

    \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'user', 'guard_name' => 'web']);

    // Three independent sessions for the same user.
    $tA = $user->createToken('a')->plainTextToken;
    $tB = $user->createToken('b')->plainTextToken;
    $tC = $user->createToken('c')->plainTextToken;

    foreach ([$tA, $tB, $tC] as $t) {
        AuthSessionExtended::create([
            'user_id'          => $user->id,
            'sanctum_token_id' => explode('|', $t)[0],
            'platform'         => 'mobile',
            'last_active_at'   => now(),
        ]);
    }

    expect($user->tokens()->count())->toBe(3);

    $response = $this->withHeaders(['Authorization' => 'Bearer ' . $tA])
        ->postJson('/auth/logout/all');

    $response->assertOk()->assertJson(['success' => true]);

    // Database-level: only the calling token survives.
    expect($user->tokens()->count())->toBe(1);
    expect($user->tokens()->first()->id)->toBe((int) explode('|', $tA)[0]);
});
