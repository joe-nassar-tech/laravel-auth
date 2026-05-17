<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Services;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Joe404\LaravelAuth\Events\AccountDeleted;
use Joe404\LaravelAuth\Events\AccountPurged;
use Joe404\LaravelAuth\Events\AccountRestored;
use Joe404\LaravelAuth\Exceptions\AuthException;
use Joe404\LaravelAuth\Models\DeletedAccount;
use Joe404\LaravelAuth\Notifications\AccountDeletedNotification;
use Joe404\LaravelAuth\Notifications\AccountPurgedNotification;
use Joe404\LaravelAuth\Notifications\AccountRestoredNotification;
use Joe404\LaravelAuth\Support\AccountStatus;

class AccountDeletionService
{
    public function __construct(
        private readonly SessionService $sessionService,
        private readonly TokenService $tokenService,
        private readonly UniqueColumnResolver $uniqueResolver,
        private readonly AccountAuditService $audit,
    ) {}

    /**
     * Soft-delete the user and stage the deleted_accounts audit row. Unique
     * columns are deliberately NOT nulled here — they remain reserved during
     * the grace window so a login can auto-restore cleanly. The purge worker
     * nulls them after grace_days has elapsed.
     */
    public function delete(User $user, ?string $reason = null): DeletedAccount
    {
        if (! (bool) config('auth_system.account.deletion.enabled', true)) {
            throw new AuthException('Account deletion is disabled.', 'account_deletion_disabled');
        }

        $graceDays = (int) config('auth_system.account.deletion.grace_days', 30);
        $purgeAt   = now()->addDays($graceDays);

        $deleted = DB::transaction(function () use ($user, $reason, $purgeAt): DeletedAccount {
            $deleted = null;

            if ((bool) config('auth_system.account.deletion.move_to_deleted_table', true)) {
                $deleted = DeletedAccount::create([
                    'original_user_id'   => (int) $user->getKey(),
                    'email'              => $user->email ?? null,
                    'username'           => $user->username ?? null,
                    'delete_reason'      => $reason,
                    'snapshot'           => $user->toArray(),
                    'deleted_at'         => now(),
                    'scheduled_purge_at' => $purgeAt,
                ]);
            }

            $column = AccountStatus::column();
            $user->{$column}        = AccountStatus::DELETED;
            $user->status_changed_at = now();
            $user->status_reason     = $reason;
            $user->save();

            // Soft-delete if the host User model uses SoftDeletes; otherwise
            // a manual deleted_at write keeps timeline tracking consistent.
            if ($this->usesSoftDeletes($user)) {
                $user->delete();
            } elseif (\Illuminate\Support\Facades\Schema::hasColumn($user->getTable(), 'deleted_at')) {
                $user->forceFill(['deleted_at' => now()])->save();
            }

            return $deleted ?? new DeletedAccount([
                'original_user_id'   => (int) $user->getKey(),
                'scheduled_purge_at' => $purgeAt,
                'deleted_at'         => now(),
            ]);
        });

        $this->tokenService->revokeAll($user);
        $this->sessionService->deleteAll($user);

        $this->audit->logStatusChange(
            $user,
            AccountStatus::ACTIVE,
            AccountStatus::DELETED,
            $reason,
            $purgeAt,
            ['actor_type' => 'user', 'actor_id' => (int) $user->getKey(), 'source' => 'self_delete'],
        );

        AccountDeleted::dispatch($user, $deleted, $purgeAt, $reason);

        if ((bool) config('auth_system.mail.account_notifications_enabled.deleted', true)) {
            $this->notifyDeleted($user, $purgeAt, $graceDays);
        }

        return $deleted;
    }

    /**
     * Restore a soft-deleted user — clears deleted_at, flips status back to
     * active, drops the deleted_accounts audit row. Called automatically by
     * AuthService::login() when credentials match a deleted-but-in-grace user.
     */
    public function restore(User $user, string $trigger = 'login'): void
    {
        if ($this->usesSoftDeletes($user) && method_exists($user, 'restore')) {
            $user->restore();
        } elseif (\Illuminate\Support\Facades\Schema::hasColumn($user->getTable(), 'deleted_at')) {
            $user->forceFill(['deleted_at' => null])->save();
        }

        $column = AccountStatus::column();
        $user->{$column}        = AccountStatus::ACTIVE;
        $user->status_changed_at = now();
        $user->status_reason     = null;
        $user->save();

        DeletedAccount::where('original_user_id', $user->getKey())
            ->whereNull('purged_at')
            ->delete();

        $this->audit->logStatusChange(
            $user,
            AccountStatus::DELETED,
            AccountStatus::ACTIVE,
            null,
            null,
            ['actor_type' => 'system', 'source' => $trigger === 'login' ? 'login_auto_restore' : 'restore'],
        );

        AccountRestored::dispatch($user, $trigger);

        if ((bool) config('auth_system.mail.account_notifications_enabled.restored', true)) {
            $this->notifyRestored($user, $trigger);
        }
    }

    /**
     * Find the (soft-deleted) user behind a deleted_accounts entry, if still
     * present. Returns null when the users row was hard-deleted by an earlier
     * purge or by the host app directly.
     */
    public function findDeletedUser(DeletedAccount $entry): ?User
    {
        /** @var class-string<User> $userModel */
        $userModel = config('auth.providers.users.model', \App\Models\User::class);

        $query = $userModel::query();

        if (in_array(SoftDeletes::class, class_uses_recursive($userModel), true)) {
            /** @phpstan-ignore-next-line */
            $query = $query->withTrashed();
        }

        /** @var User|null $user */
        $user = $query->find($entry->original_user_id);

        return $user;
    }

    /**
     * Permanently anonymise a single deleted_accounts entry once the grace
     * window has elapsed. Nulls every detected unique column on the users row
     * so the email/username can be reclaimed, optionally hard-deletes the
     * users row, then marks the audit row as purged.
     *
     * @return array{nulled: array<int, string>, hard_deleted: bool}
     */
    public function purge(DeletedAccount $entry): array
    {
        $user           = $this->findDeletedUser($entry);
        $nulled         = [];
        $hardDelete     = (bool) config('auth_system.account.deletion.hard_delete_after_grace', false);
        $nullUniques    = (bool) config('auth_system.account.deletion.null_uniques_after_grace', true);

        if ($user !== null) {
            if ($nullUniques) {
                $columns = $this->uniqueResolver->resolve($user->getTable());

                if ($columns !== []) {
                    $update = array_fill_keys($columns, null);
                    DB::table($user->getTable())
                        ->where($user->getKeyName(), $user->getKey())
                        ->update($update);
                    $nulled = $columns;
                }
            }

            if ($hardDelete) {
                if ($this->usesSoftDeletes($user) && method_exists($user, 'forceDelete')) {
                    $user->forceDelete();
                } else {
                    DB::table($user->getTable())
                        ->where($user->getKeyName(), $user->getKey())
                        ->delete();
                }
            }
        }

        $entry->forceFill(['purged_at' => now()])->save();

        $this->audit->logStatusChange(
            (int) $entry->original_user_id,
            AccountStatus::DELETED,
            null,
            'Purged after grace period.',
            null,
            ['actor_type' => 'system', 'source' => 'purge_worker'],
        );

        AccountPurged::dispatch($entry, $nulled, $hardDelete);

        if ($user !== null
            && (bool) config('auth_system.mail.account_notifications_enabled.purged', false)
        ) {
            $this->notifyPurged($user);
        }

        return ['nulled' => $nulled, 'hard_deleted' => $hardDelete];
    }

    private function usesSoftDeletes(User $user): bool
    {
        return in_array(SoftDeletes::class, class_uses_recursive($user), true);
    }

    private function notifyDeleted(User $user, Carbon $purgeAt, int $graceDays): void
    {
        $class = (string) (config('auth_system.mail.account_deleted_notification')
            ?: AccountDeletedNotification::class);

        if (! class_exists($class)) {
            return;
        }

        try {
            Notification::send($user, new $class($purgeAt, $graceDays));
        } catch (\Throwable) {}
    }

    private function notifyRestored(User $user, string $trigger): void
    {
        $class = (string) (config('auth_system.mail.account_restored_notification')
            ?: AccountRestoredNotification::class);

        if (! class_exists($class)) {
            return;
        }

        try {
            Notification::send($user, new $class($trigger));
        } catch (\Throwable) {}
    }

    private function notifyPurged(User $user): void
    {
        $class = (string) (config('auth_system.mail.account_purged_notification')
            ?: AccountPurgedNotification::class);

        if (! class_exists($class)) {
            return;
        }

        try {
            Notification::send($user, new $class());
        } catch (\Throwable) {}
    }
}
