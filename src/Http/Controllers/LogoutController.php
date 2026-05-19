<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Joe404\LaravelAuth\Http\Concerns\ResolvesMessages;
use Joe404\LaravelAuth\Http\Concerns\RespondsWithJson;
use Joe404\LaravelAuth\Services\AuthService;

class LogoutController extends Controller
{
    use ResolvesMessages, RespondsWithJson;

    public function __construct(
        private readonly AuthService $authService,
    ) {}

    public function logout(Request $request): JsonResponse
    {
        $this->authService->logout($request);

        return $this->success($this->msg('logout_success', 'Logged out successfully.'));
    }

    public function logoutAll(Request $request): JsonResponse
    {
        $this->authService->logoutAll($request->user(), $request);

        return $this->success($this->msg('logout_all_success', 'Logged out of all sessions.'));
    }

    /**
     * Force-destroy the current session WITHOUT requiring authentication.
     *
     * Use case: a SPA that booted with an orphaned session cookie — for example
     * the authenticated user was hard-deleted from the database outside the
     * normal deletion flow, so `/auth/me` now returns 401 but the browser's
     * session cookie still points at a session in storage that references
     * the missing user. The regular `/auth/logout` route is gated by
     * `auth:sanctum` and rejects the request as 401 BEFORE the controller can
     * clear anything, leaving the orphan in place. The next registration
     * attempt then runs `Auth::login` on top of the stale session, the new
     * `Set-Cookie` may be ignored by the browser, and `/auth/me` keeps
     * failing in a loop until the user clears cookies manually.
     *
     * This endpoint takes whatever session the request carries and
     * invalidates it server-side (drops the storage entry) and rotates the
     * CSRF token. The browser receives a fresh `Set-Cookie` so the next
     * request starts from a clean slate. Safe to call on every 401 from
     * `/auth/me` at app boot.
     */
    public function clearOrphanSession(Request $request): JsonResponse
    {
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return $this->success($this->msg('session_cleared', 'Session cleared.'));
    }
}
