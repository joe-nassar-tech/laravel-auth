<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Models;

use Illuminate\Database\Eloquent\Model;

class AuthRefreshToken extends Model
{
    public $timestamps = false;

    protected $table = 'auth_refresh_tokens';

    protected $fillable = [
        'user_id',
        'access_token_id',
        'token_hash',
        'family_id',
        'parent_id',
        'expires_at',
        'consumed_at',
        'revoked_at',
        'revoked_reason',
    ];

    protected $casts = [
        'expires_at'   => 'datetime',
        'consumed_at'  => 'datetime',
        'revoked_at'   => 'datetime',
        'created_at'   => 'datetime',
    ];

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public static function findToken(string $rawToken): ?self
    {
        return static::where('token_hash', hash('sha256', $rawToken))->first();
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }
}
