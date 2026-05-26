<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User;

class AuthTwoFactorChallenge extends Model
{
    const UPDATED_AT = null;

    protected $table = 'auth_two_factor_challenges';

    protected $fillable = [
        'challenge_token',
        'user_id',
        'method',
        'attempts',
        'client_type',
        'ip_address',
        'fingerprint_hash',
        'expires_at',
        'consumed_at',
        'created_at',
    ];

    protected $casts = [
        'attempts'    => 'int',
        'expires_at'  => 'datetime',
        'consumed_at' => 'datetime',
        'created_at'  => 'datetime',
    ];

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }

    public function isUsable(): bool
    {
        return ! $this->isExpired() && ! $this->isConsumed();
    }

    public function user(): BelongsTo
    {
        /** @var class-string<User> $userModel */
        $userModel = config('auth.providers.users.model', \App\Models\User::class);

        return $this->belongsTo($userModel, 'user_id');
    }
}
