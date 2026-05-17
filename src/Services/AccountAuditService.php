<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Services;

use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Joe404\LaravelAuth\Models\AccountStatusLog;

/**
 * Persists account-status transitions + admin notes so multi-admin teams
 * can see the full history of who did what and why without pinging each
 * other. Driven entirely by config:
 *
 *   account.audit.enabled              → master switch
 *   account.audit.log_status_changes   → toggle status-change entries
 *   account.audit.log_system_actions   → toggle entries when actor=system
 *   account.audit.capture_request_meta → toggle ip + user_agent capture
 *
 * Every write is best-effort: a failure to log must never block the
 * underlying action (status change, login, deletion, …).
 */
class AccountAuditService
{
    public const ACTOR_ADMIN  = 'admin';
    public const ACTOR_USER   = 'user';
    public const ACTOR_SYSTEM = 'system';

    public const ACTION_STATUS_CHANGE = 'status_change';
    public const ACTION_NOTE          = 'note';

    /**
     * Log a status transition. Silently no-ops when audit is disabled, when
     * status logging is off, or when the actor is system and system actions
     * are not being logged.
     *
     * @param  array{
     *     actor?: User|null,
     *     actor_type?: string,
     *     actor_id?: int|null,
     *     comment?: string|null,
     *     source?: string|null,
     * }  $context
     */
    public function logStatusChange(
        User|int $user,
        ?string $fromStatus,
        ?string $toStatus,
        ?string $reason = null,
        ?Carbon $expiresAt = null,
        array $context = [],
    ): ?AccountStatusLog {
        if (! $this->statusLoggingEnabled()) {
            return null;
        }

        [$actorType, $actorId] = $this->resolveActor($context);

        if ($actorType === self::ACTOR_SYSTEM && ! $this->systemLoggingEnabled()) {
            return null;
        }

        return $this->write(
            userId:     $user instanceof User ? (int) $user->getKey() : $user,
            actorType:  $actorType,
            actorId:    $actorId,
            action:     self::ACTION_STATUS_CHANGE,
            fromStatus: $fromStatus,
            toStatus:   $toStatus,
            reason:     $reason,
            comment:    $context['comment'] ?? null,
            expiresAt:  $expiresAt,
            source:     $context['source'] ?? null,
        );
    }

    /**
     * Log a standalone admin note (no status change).
     *
     * @param  array{
     *     actor?: User|null,
     *     actor_type?: string,
     *     actor_id?: int|null,
     *     source?: string|null,
     * }  $context
     */
    public function logNote(
        User|int $user,
        string $comment,
        ?string $reason = null,
        array $context = [],
    ): ?AccountStatusLog {
        if (! $this->enabled() || ! $this->notesEnabled()) {
            return null;
        }

        [$actorType, $actorId] = $this->resolveActor($context);

        return $this->write(
            userId:     $user instanceof User ? (int) $user->getKey() : $user,
            actorType:  $actorType,
            actorId:    $actorId,
            action:     self::ACTION_NOTE,
            fromStatus: null,
            toStatus:   null,
            reason:     $reason,
            comment:    $comment,
            expiresAt:  null,
            source:     $context['source'] ?? 'admin_note',
        );
    }

    private function write(
        int $userId,
        string $actorType,
        ?int $actorId,
        string $action,
        ?string $fromStatus,
        ?string $toStatus,
        ?string $reason,
        ?string $comment,
        ?Carbon $expiresAt,
        ?string $source,
    ): ?AccountStatusLog {
        try {
            [$ip, $ua] = $this->captureRequestMeta();

            return AccountStatusLog::create([
                'user_id'     => $userId,
                'actor_type'  => $actorType,
                'actor_id'    => $actorId,
                'action'      => $action,
                'from_status' => $fromStatus,
                'to_status'   => $toStatus,
                'reason'      => $reason,
                'comment'     => $comment,
                'expires_at'  => $expiresAt,
                'source'      => $source,
                'ip_address'  => $ip,
                'user_agent'  => $ua,
                'created_at'  => now(),
            ]);
        } catch (\Throwable $e) {
            report($e);
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{0:string, 1:int|null}
     */
    private function resolveActor(array $context): array
    {
        // Caller may force values explicitly (admin endpoint passes
        // actor=current user; system jobs pass actor_type=system).
        if (isset($context['actor_type'])) {
            return [
                (string) $context['actor_type'],
                isset($context['actor_id']) ? (int) $context['actor_id'] : null,
            ];
        }

        $actor = $context['actor'] ?? null;
        if ($actor instanceof User) {
            return [self::ACTOR_ADMIN, (int) $actor->getKey()];
        }

        return [self::ACTOR_SYSTEM, null];
    }

    /** @return array{0:?string, 1:?string} */
    private function captureRequestMeta(): array
    {
        if (! (bool) config('auth_system.account.audit.capture_request_meta', true)) {
            return [null, null];
        }

        try {
            /** @var Request|null $request */
            $request = app('request');
            if (! $request instanceof Request) {
                return [null, null];
            }

            return [
                $request->ip(),
                substr((string) $request->userAgent(), 0, 255),
            ];
        } catch (\Throwable) {
            return [null, null];
        }
    }

    public function enabled(): bool
    {
        return (bool) config('auth_system.account.audit.enabled', true);
    }

    private function statusLoggingEnabled(): bool
    {
        return $this->enabled() && (bool) config('auth_system.account.audit.log_status_changes', true);
    }

    private function systemLoggingEnabled(): bool
    {
        return (bool) config('auth_system.account.audit.log_system_actions', true);
    }

    private function notesEnabled(): bool
    {
        return (bool) config('auth_system.account.audit.notes.enabled', true);
    }
}
