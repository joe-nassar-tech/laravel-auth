<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Models;

use Illuminate\Database\Eloquent\Model;

class AuthTwoFactorBackupCode extends Model
{
    const UPDATED_AT = null;

    protected $table = 'auth_two_factor_backup_codes';

    protected $fillable = [
        'user_id',
        'code_hash',
        'used_at',
        'created_at',
    ];

    protected $hidden = [
        'code_hash',
    ];

    protected $casts = [
        'used_at'    => 'datetime',
        'created_at' => 'datetime',
    ];

    public function isUsed(): bool
    {
        return $this->used_at !== null;
    }
}
