<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User;

class Referral extends Model
{
    public const STATUS_PENDING    = 'pending';
    public const STATUS_VALID      = 'valid';
    public const STATUS_SUSPICIOUS = 'suspicious';
    public const STATUS_BLOCKED    = 'blocked';
    public const STATUS_EXPIRED    = 'expired';

    protected $table = 'referrals';

    protected $fillable = [
        'referrer_id',
        'referred_id',
        'referral_code',
        'status',
        'referrer_fingerprint',
        'referred_fingerprint',
        'referrer_ip',
        'referred_ip',
        'ip_match',
        'device_match',
        'redeemed_at',
        'admin_note',
    ];

    protected $casts = [
        'ip_match'     => 'bool',
        'device_match' => 'bool',
        'redeemed_at'  => 'datetime',
    ];

    public function referrer(): BelongsTo
    {
        return $this->belongsTo($this->userModel(), 'referrer_id');
    }

    public function referred(): BelongsTo
    {
        return $this->belongsTo($this->userModel(), 'referred_id');
    }

    public function isValid(): bool
    {
        return $this->status === self::STATUS_VALID;
    }

    public function isRedeemable(): bool
    {
        return $this->status === self::STATUS_VALID && $this->redeemed_at === null;
    }

    /**
     * @return class-string<User>
     */
    private function userModel(): string
    {
        /** @var class-string<User> $model */
        $model = config('auth.providers.users.model', \App\Models\User::class);

        return $model;
    }
}
