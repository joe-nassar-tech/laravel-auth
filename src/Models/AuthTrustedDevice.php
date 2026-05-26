<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User;

class AuthTrustedDevice extends Model
{
    public const LEVEL_UNTRUSTED = 'untrusted';
    public const LEVEL_LOW       = 'low';
    public const LEVEL_MEDIUM    = 'medium';
    public const LEVEL_HIGH      = 'high';

    /** Trusted levels (excludes untrusted sentinel). */
    public const LEVELS = [self::LEVEL_LOW, self::LEVEL_MEDIUM, self::LEVEL_HIGH];

    /** Full set including the "not trusted yet / revoked" sentinel. */
    public const ALL_LEVELS = [self::LEVEL_UNTRUSTED, self::LEVEL_LOW, self::LEVEL_MEDIUM, self::LEVEL_HIGH];

    protected $table = 'auth_trusted_devices';

    protected $fillable = [
        'user_id',
        'fingerprint_hash',
        'secret_hash',
        'device_name',
        'platform',
        'browser',
        'os',
        'ip_address',
        'level',
        'admin_granted',
        'first_seen_at',
        'last_seen_at',
        'trusted_at',
        'expires_at',
        'revoked_at',
    ];

    protected $hidden = [
        'secret_hash',
    ];

    protected $casts = [
        'admin_granted' => 'bool',
        'first_seen_at' => 'datetime',
        'last_seen_at'  => 'datetime',
        'trusted_at'    => 'datetime',
        'expires_at'    => 'datetime',
        'revoked_at'    => 'datetime',
    ];

    public function user(): BelongsTo
    {
        /** @var class-string<User> $userModel */
        $userModel = config('auth.providers.users.model', \App\Models\User::class);

        return $this->belongsTo($userModel, 'user_id');
    }

    public function isActive(): bool
    {
        if ($this->revoked_at !== null) {
            return false;
        }

        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return false;
        }

        return $this->trusted_at !== null;
    }

    public function levelRank(): int
    {
        return match ($this->level) {
            self::LEVEL_HIGH   => 3,
            self::LEVEL_MEDIUM => 2,
            self::LEVEL_LOW    => 1,
            default            => 0,
        };
    }

    public static function rankOf(string $level): int
    {
        return match ($level) {
            self::LEVEL_HIGH      => 3,
            self::LEVEL_MEDIUM    => 2,
            self::LEVEL_LOW       => 1,
            self::LEVEL_UNTRUSTED => 0,
            default               => 0,
        };
    }
}
