<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Tests\Fixtures;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens;
    use HasRoles;
    use Notifiable;
    use SoftDeletes;

    protected $table = 'users';

    protected $fillable = [
        'name',
        'email',
        'password',
        'email_verified_at',
        'google_id',
        'password_change_required',
        'is_active',
        'last_login_at',
        // v2.2 — opt-in features
        'referral_code',
        'username',
        'username_normalized',
        // v2.4 — account status / deletion
        'account_status',
        'status_changed_at',
        'status_reason',
        'status_expires_at',
        // v2.6 — phone + 2FA
        'phone',
        'phone_verified_at',
        'two_factor_required',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at'        => 'datetime',
        'last_login_at'            => 'datetime',
        'password_change_required' => 'boolean',
        'is_active'                => 'boolean',
        'status_changed_at'        => 'datetime',
        'status_expires_at'        => 'datetime',
        'phone_verified_at'        => 'datetime',
        'two_factor_required'      => 'boolean',
    ];

    public function hasVerifiedEmail(): bool
    {
        return $this->email_verified_at !== null;
    }

    public function markEmailAsVerified(): bool
    {
        return $this->forceFill([
            'email_verified_at' => $this->freshTimestamp(),
        ])->save();
    }

    public function getEmailForVerification(): string
    {
        return $this->email;
    }

    public function sendEmailVerificationNotification(): void
    {
        // No-op in tests
    }
}
