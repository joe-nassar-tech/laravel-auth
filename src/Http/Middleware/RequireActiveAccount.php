<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Joe404\LaravelAuth\Contracts\ResponseFormatterContract;
use Joe404\LaravelAuth\Services\AccountStatusService;
use Joe404\LaravelAuth\Support\AccountStatus;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per-request guard that rejects authenticated requests if the user's status
 * is in the configured login_blocked list (typically disabled/suspended). A
 * mid-session status change therefore takes effect on the very next request
 * without waiting for the token to expire.
 *
 * "deleted" is intentionally not handled here — login auto-restores during
 * grace, and after the worker purges, the users row no longer exists so the
 * auth guard rejects naturally.
 */
class RequireActiveAccount
{
    public function __construct(
        private readonly AccountStatusService $statusService,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! (bool) config('auth_system.account.status.enabled', true)) {
            return $next($request);
        }

        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        $status = $this->statusService->current($user);

        if (! AccountStatus::blocksLogin($status)) {
            return $next($request);
        }

        /** @var ResponseFormatterContract $formatter */
        $formatter = app(ResponseFormatterContract::class);

        $key = "account_{$status}";
        $msg = $this->resolveMessage($key, ucfirst($status) . ' accounts cannot access this resource.');

        return response()->json(
            $formatter->format(false, $msg, [], []),
            403,
        );
    }

    private function resolveMessage(string $key, string $default): string
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
}
