<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Models;

use Illuminate\Database\Eloquent\Model;

class AuthPhoneOtpCode extends Model
{
    const UPDATED_AT = null;

    public const PURPOSE_PHONE_VERIFY  = 'phone_verify';
    public const PURPOSE_TWO_FACTOR    = 'two_factor_sms';

    protected $table = 'auth_phone_otp_codes';

    protected $fillable = [
        'user_id',
        'phone',
        'purpose',
        'code_hash',
        'channel',
        'attempts',
        'expires_at',
        'consumed_at',
        'created_at',
    ];

    protected $hidden = [
        'code_hash',
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
}
