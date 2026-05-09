<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Models;

use Illuminate\Database\Eloquent\Model;

class AuthOtpCode extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'auth_otp_codes';

    protected $fillable = [
        'user_id',
        'email',
        'type',
        'token',
        'temp_token',
        'expires_at',
        'used_at',
        'failed_attempts',
    ];

    protected $casts = [
        'expires_at'      => 'datetime',
        'used_at'         => 'datetime',
        'failed_attempts' => 'integer',
    ];

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isUsed(): bool
    {
        return $this->used_at !== null;
    }
}
