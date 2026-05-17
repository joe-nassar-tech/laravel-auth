<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Support;

/**
 * Canonical account-status names. The "allowed" list lives in config so host
 * apps can introduce custom statuses without touching the package — these
 * constants only exist so package internals have a stable spelling.
 */
final class AccountStatus
{
    public const ACTIVE      = 'active';
    public const DISABLED    = 'disabled';     // admin-only violation ban (Meta-style)
    public const SUSPENDED   = 'suspended';    // admin temporary ban (can be timed)
    public const DELETED     = 'deleted';      // self-service deletion in grace
    public const DEACTIVATED = 'deactivated';  // self-service hide/pause; auto-restores on login

    /** @return array<int, string> */
    public static function allowed(): array
    {
        $configured = config('auth_system.account.status.allowed');

        return is_array($configured) && $configured !== []
            ? array_values(array_map('strval', $configured))
            : [self::ACTIVE, self::DISABLED, self::SUSPENDED, self::DELETED, self::DEACTIVATED];
    }

    public static function isValid(string $status): bool
    {
        return in_array($status, self::allowed(), true);
    }

    /** @return array<int, string> */
    public static function loginBlocked(): array
    {
        $configured = config('auth_system.account.status.login_blocked');

        return is_array($configured)
            ? array_values(array_map('strval', $configured))
            : [self::DISABLED, self::SUSPENDED];
    }

    public static function blocksLogin(string $status): bool
    {
        return in_array($status, self::loginBlocked(), true);
    }

    public static function column(): string
    {
        return (string) config('auth_system.account.status.column', 'account_status');
    }

    public static function default(): string
    {
        return (string) config('auth_system.account.status.default', self::ACTIVE);
    }
}
