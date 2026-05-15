<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;

class AuthSessionExtended extends Model
{
    /** @var string|null */
    const UPDATED_AT = null;

    protected $table = 'auth_sessions_extended';

    protected $fillable = [
        'user_id',
        'session_id',
        'sanctum_token_id',
        'platform',
        'browser',
        'os',
        'device_model',
        'device_marketing_name',
        'device_code',
        'device_platform',
        'ip_address',
        'country',
        'city',
        'last_active_at',
        'created_at',
    ];

    protected $casts = [
        'last_active_at' => 'datetime',
        'created_at'     => 'datetime',
    ];

    public function user(): BelongsTo
    {
        /** @var class-string<User> $userModel */
        $userModel = config('auth.providers.users.model', \App\Models\User::class);

        return $this->belongsTo($userModel, 'user_id');
    }

    public function isCurrent(Request $request): bool
    {
        if ($this->sanctum_token_id !== null) {
            $token = $request->user()?->currentAccessToken();

            return $token instanceof \Laravel\Sanctum\PersonalAccessToken
                && $this->sanctum_token_id === $token->id;
        }

        try {
            return $this->session_id === $request->session()->getId();
        } catch (\Throwable) {
            return false;
        }
    }
}
