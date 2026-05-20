<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Services;

use RuntimeException;

/**
 * Internal signal: the request's client type is not in
 * auth_system.referral_code.allowed_clients, so the referral must fail
 * silently — the controller returns a success envelope and the package
 * does not persist anything or fire any event.
 *
 * This is a deliberate design choice: a mobile-only referral feature
 * should not leak its existence to web clients, and vice versa.
 *
 * Not user-facing. Never reaches the JSON response.
 */
class SilentReferralFailure extends RuntimeException
{
}
