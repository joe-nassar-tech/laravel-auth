<?php

declare(strict_types=1);

use Joe404\LaravelAuth\Models\AuthRefreshToken;
use Joe404\LaravelAuth\Services\TokenService;
use Joe404\LaravelAuth\Tests\Fixtures\User;
use Laravel\Sanctum\PersonalAccessToken;

beforeEach(function (): void {
    \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'user', 'guard_name' => 'web']);
});

it('rotates refresh tokens with linked family and parent', function (): void {
    /** @var User $user */
    $user = User::create([
        'name' => 'rt', 'email' => 'rt@example.com',
        'password' => bcrypt('x'), 'email_verified_at' => now(), 'is_active' => true,
    ]);

    /** @var TokenService $svc */
    $svc = app(TokenService::class);
    $first = $svc->issue($user, 'mobile');

    $rotated = $svc->refresh($first['plain_refresh_token'], 'mobile');

    $firstRow   = AuthRefreshToken::where('token_hash', hash('sha256', $first['plain_refresh_token']))->first();
    $secondRow  = AuthRefreshToken::where('token_hash', hash('sha256', $rotated['plain_refresh_token']))->first();

    expect($firstRow->consumed_at)->not->toBeNull();
    expect($secondRow->family_id)->toBe($firstRow->family_id);
    expect($secondRow->parent_id)->toBe($firstRow->id);
});

it('revokes the entire family when a consumed refresh token is replayed', function (): void {
    /** @var User $user */
    $user = User::create([
        'name' => 'rt2', 'email' => 'rt2@example.com',
        'password' => bcrypt('x'), 'email_verified_at' => now(), 'is_active' => true,
    ]);

    /** @var TokenService $svc */
    $svc = app(TokenService::class);
    $a = $svc->issue($user, 'mobile');
    $b = $svc->refresh($a['plain_refresh_token'], 'mobile');

    // Replay the original (now consumed) refresh token — must trip reuse detection.
    expect(fn () => $svc->refresh($a['plain_refresh_token'], 'mobile'))
        ->toThrow(\Joe404\LaravelAuth\Exceptions\AuthException::class);

    $familyId = AuthRefreshToken::where('token_hash', hash('sha256', $a['plain_refresh_token']))->value('family_id');

    // Every row in the family must now be revoked, including the most recent one.
    $unrevokedInFamily = AuthRefreshToken::where('family_id', $familyId)->whereNull('revoked_at')->count();
    expect($unrevokedInFamily)->toBe(0);

    // The latest access token must also be gone.
    $latestRow = AuthRefreshToken::where('token_hash', hash('sha256', $b['plain_refresh_token']))->first();
    expect(PersonalAccessToken::find($latestRow->access_token_id))->toBeNull();
});
