<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Services;

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
// AccountAuditService is in the same namespace; no use statement needed.
use Joe404\LaravelAuth\Events\AccountStatusChanged;
use Joe404\LaravelAuth\Exceptions\AuthException;
use Joe404\LaravelAuth\Notifications\AccountStatusChangedNotification;
use Joe404\LaravelAuth\Support\AccountStatus;

class AccountStatusService
{
    public function __construct(
        private readonly SessionService $sessionService,
        private readonly TokenService $tokenService,
        private readonly AccountAuditService $audit,
    ) {}

    /**
     * Reads the user's current status from the configured column.
     *
     * If the user is on a timed ban (status_expires_at <= now) and auto-unban
     * is enabled, this method reverts them to "active" on the spot before
     * returning. That way every code path that reads status — login,
     * middleware, /me — sees the post-revert truth without waiting for the
     * scheduled sweep worker.
     *
     * Defaults to "active" when the column does not exist (handy for hosts
     * that haven't run the v2.4 migration yet).
     */
    public function current(User $user): string
    {
        $this->revertIfExpired($user);

        $column = AccountStatus::column();
        $value  = $user->{$column} ?? AccountStatus::default();

        return (string) $value;
    }

    /**
     * @param  array{
     *     actor?: User|null,
     *     actor_type?: string,
     *     actor_id?: int|null,
     *     comment?: string|null,
     *     source?: string|null,
     * }  $context  Forwarded to the audit log so multi-admin teams can see
     *              who did what and why. See AccountAuditService for keys.
     */
    public function changeStatus(
        User $user,
        string $newStatus,
        ?string $reason = null,
        ?Carbon $expiresAt = null,
        array $context = [],
    ): void {
        if (! AccountStatus::isValid($newStatus)) {
            throw new AuthException(
                "Invalid account status: {$newStatus}.",
                'account_status_invalid',
            );
        }

        // Read previous WITHOUT triggering a lazy revert — we are about to
        // overwrite the status anyway, and we want to know what was actually
        // on disk so the AccountStatusChanged event reports it accurately.
        $column   = AccountStatus::column();
        $previous = (string) ($user->{$column} ?? AccountStatus::default());

        if ($previous === $newStatus && $this->isSameExpiry($user, $expiresAt)) {
            return;
        }

        $user->{$column}         = $newStatus;
        $user->status_changed_at = now();
        $user->status_reason     = $reason;

        // Clear expiry whenever we return to "active" — a permanent restore.
        // Otherwise honor the caller-supplied expiry (nullable = permanent ban).
        $user->status_expires_at = $newStatus === AccountStatus::ACTIVE
            ? null
            : $expiresAt;

        $user->save();

        if (
            $previous === AccountStatus::ACTIVE
            && $newStatus !== AccountStatus::ACTIVE
            && (bool) config('auth_system.account.status.revoke_sessions_on_change', true)
        ) {
            $this->tokenService->revokeAll($user);
            $this->sessionService->deleteAll($user);
        }

        $this->audit->logStatusChange(
            $user,
            $previous,
            $newStatus,
            $reason,
            $expiresAt,
            $context,
        );

        AccountStatusChanged::dispatch($user, $previous, $newStatus, $reason);

        if ((bool) config('auth_system.mail.account_notifications_enabled.status_changed', false)) {
            $this->notify($user, $previous, $newStatus, $reason);
        }
    }

    /**
     * If the user is on a timed ban whose expiry has elapsed, flip them back
     * to "active" through the normal changeStatus path so listeners (events,
     * notifications) fire exactly once. Idempotent and cheap to call on every
     * status read.
     */
    public function revertIfExpired(User $user, string $source = 'auto_unban_lazy'): bool
    {
        if (! (bool) config('auth_system.account.status.auto_unban.enabled', true)) {
            return false;
        }

        $expiresAt = $user->status_expires_at ?? null;

        if ($expiresAt === null) {
            return false;
        }

        $expiry = $expiresAt instanceof Carbon ? $expiresAt : Carbon::parse((string) $expiresAt);
        if ($expiry->isFuture()) {
            return false;
        }

        $column = AccountStatus::column();
        $currentRaw = (string) ($user->{$column} ?? AccountStatus::default());

        if ($currentRaw === AccountStatus::ACTIVE) {
            // Already active but stale expiry — just clear it without firing.
            $user->status_expires_at = null;
            $user->save();
            return false;
        }

        $this->changeStatus(
            $user,
            AccountStatus::ACTIVE,
            'Auto-unban: ban period elapsed.',
            null,
            ['actor_type' => AccountAuditService::ACTOR_SYSTEM, 'source' => $source],
        );
        return true;
    }

    private function isSameExpiry(User $user, ?Carbon $expiresAt): bool
    {
        $existing = $user->status_expires_at ?? null;

        if ($existing === null && $expiresAt === null) {
            return true;
        }
        if ($existing === null || $expiresAt === null) {
            return false;
        }

        $existingC = $existing instanceof Carbon ? $existing : Carbon::parse((string) $existing);

        return $existingC->equalTo($expiresAt);
    }

    /**
     * Throws if the user's current status forbids login. Called by AuthService
     * after credential validation but before any token / session is issued.
     */
    public function assertCanLogin(User $user): void
    {
        if (! (bool) config('auth_system.account.status.enabled', true)) {
            return;
        }

        $status = $this->current($user);

        if (! AccountStatus::blocksLogin($status)) {
            return;
        }

        $key = "account_{$status}"; // e.g. account_disabled, account_suspended
        $msg = $this->messageFor($key, ucfirst($status) . ' accounts cannot log in.');

        throw new AuthException($msg, $key);
    }

    private function messageFor(string $key, string $default): string
    {
        $override = config("auth_system.errors.{$key}");
        if (is_string($override) && $override !== '') {
            return $override;
        }

        $translated = trans("auth_system::errors.{$key}");
        if (is_string($translated) && $translated !== "auth_system::errors.{$key}" && $translated !== '') {
            return $translated;
        }

        return $default;
    }

    private function notify(User $user, string $previous, string $newStatus, ?string $reason): void
    {
        $class = (string) (config('auth_system.mail.account_status_changed_notification')
            ?: AccountStatusChangedNotification::class);

        if (! class_exists($class)) {
            return;
        }

        try {
            Notification::send($user, new $class($previous, $newStatus, $reason));
        } catch (\Throwable) {
            // Status change must not fail if the mailer is misconfigured.
        }
    }
}
