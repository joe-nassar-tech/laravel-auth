<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $original_user_id
 * @property string|null $email
 * @property string|null $username
 * @property string|null $delete_reason
 * @property array<string, mixed> $snapshot
 * @property \Illuminate\Support\Carbon $deleted_at
 * @property \Illuminate\Support\Carbon $scheduled_purge_at
 * @property \Illuminate\Support\Carbon|null $purged_at
 */
class DeletedAccount extends Model
{
    protected $table = 'deleted_accounts';

    protected $guarded = [];

    protected $casts = [
        'snapshot'           => 'array',
        'deleted_at'         => 'datetime',
        'scheduled_purge_at' => 'datetime',
        'purged_at'          => 'datetime',
        'original_user_id'   => 'integer',
    ];

    public function isWithinGrace(): bool
    {
        return $this->scheduled_purge_at !== null
            && $this->scheduled_purge_at->isFuture();
    }
}
