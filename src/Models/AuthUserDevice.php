<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User;

class AuthUserDevice extends Model
{
    // The model has its own explicit first_seen_at / last_seen_at columns.
    // Disable Eloquent's automatic created_at / updated_at to keep the
    // semantics unambiguous — "first" and "last" SEEN, not Eloquent's
    // generic timestamps.
    public $timestamps = false;

    protected $table = 'auth_user_devices';

    protected $fillable = [
        'user_id',
        'fingerprint_hash',
        'device_signature',
        'ip_address',
        'platform',
        'browser',
        'os',
        'device_model',
        'device_marketing_name',
        'device_code',
        'device_platform',
        'country',
        'city',
        'first_seen_at',
        'last_seen_at',
    ];

    protected $casts = [
        'first_seen_at' => 'datetime',
        'last_seen_at'  => 'datetime',
    ];

    public function user(): BelongsTo
    {
        /** @var class-string<User> $userModel */
        $userModel = config('auth.providers.users.model', \App\Models\User::class);

        return $this->belongsTo($userModel, 'user_id');
    }
}
