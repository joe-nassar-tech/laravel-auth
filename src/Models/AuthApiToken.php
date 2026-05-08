<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Models;

use Illuminate\Database\Eloquent\Model;

class AuthApiToken extends Model
{
    protected $table = 'auth_api_tokens';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'token_hash',
        'abilities',
        'owner_type',
        'owner_id',
        'last_used_at',
        'expires_at',
        'is_active',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'abilities'    => 'array',
        'last_used_at' => 'datetime',
        'expires_at'   => 'datetime',
        'is_active'    => 'boolean',
    ];

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function can(string $ability): bool
    {
        return in_array('*', $this->abilities, true) || in_array($ability, $this->abilities, true);
    }
}
