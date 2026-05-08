<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuthSocialAccount extends Model
{
    protected $table = 'auth_social_accounts';

    const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'provider',
        'provider_id',
        'provider_email',
        'avatar',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        /** @var class-string<Model> $userModel */
        $userModel = config('auth.providers.users.model', \App\Models\User::class);

        return $this->belongsTo($userModel, 'user_id');
    }
}
