<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Concerns;

use Joe404\LaravelAuth\Support\AccountStatus;

/**
 * Optional trait the host User model can use to read its account status with
 * a stable API regardless of the configured column name. Everything in the
 * package falls back to direct attribute access if the trait is absent.
 */
trait HasAccountStatus
{
    public function getAccountStatus(): string
    {
        $column = AccountStatus::column();
        $value  = $this->{$column} ?? AccountStatus::default();

        return (string) $value;
    }

    public function setAccountStatus(string $status, ?string $reason = null): void
    {
        $column = AccountStatus::column();
        $this->{$column} = $status;
        $this->status_changed_at = now();
        $this->status_reason = $reason;
    }

    public function isAccountActive(): bool
    {
        return $this->getAccountStatus() === AccountStatus::ACTIVE;
    }
}
