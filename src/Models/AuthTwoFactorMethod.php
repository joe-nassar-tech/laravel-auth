<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User;

class AuthTwoFactorMethod extends Model
{
    protected $table = 'auth_two_factor_methods';

    protected $fillable = [
        'user_id',
        'type',
        'secret_encrypted',
        'is_default',
        'verified_at',
        'last_used_at',
    ];

    protected $hidden = [
        'secret_encrypted',
    ];

    protected $casts = [
        'is_default'   => 'bool',
        'verified_at'  => 'datetime',
        'last_used_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        /** @var class-string<User> $userModel */
        $userModel = config('auth.providers.users.model', \App\Models\User::class);

        return $this->belongsTo($userModel, 'user_id');
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }
}
