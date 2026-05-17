<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $user_id
 * @property string $actor_type
 * @property int|null $actor_id
 * @property string $action
 * @property string|null $from_status
 * @property string|null $to_status
 * @property string|null $reason
 * @property string|null $comment
 * @property \Illuminate\Support\Carbon|null $expires_at
 * @property string|null $source
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property \Illuminate\Support\Carbon $created_at
 */
class AccountStatusLog extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'user_id'    => 'integer',
        'actor_id'   => 'integer',
        'expires_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function getTable(): string
    {
        return (string) config('auth_system.account.audit.table', 'account_status_logs');
    }
}
