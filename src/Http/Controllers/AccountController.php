<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Joe404\LaravelAuth\Exceptions\AuthException;
use Joe404\LaravelAuth\Http\Concerns\ResolvesMessages;
use Joe404\LaravelAuth\Http\Concerns\RespondsWithJson;
use Joe404\LaravelAuth\Http\Requests\DeactivateAccountRequest;
use Joe404\LaravelAuth\Http\Requests\DeleteAccountRequest;
use Joe404\LaravelAuth\Notifications\AccountDeactivatedNotification;
use Joe404\LaravelAuth\Services\AccountDeletionService;
use Joe404\LaravelAuth\Services\AccountStatusService;
use Joe404\LaravelAuth\Support\AccountStatus;

class AccountController extends Controller
{
    use ResolvesMessages, RespondsWithJson;

    public function __construct(
        private readonly AccountDeletionService $deletionService,
        private readonly AccountStatusService $statusService,
    ) {}

    public function destroy(DeleteAccountRequest $request): JsonResponse
    {
        if (! (bool) config('auth_system.account.deletion.enabled', true)
            || ! (bool) config('auth_system.account.deletion.self_service', true)
        ) {
            return $this->failure(
                $this->errKey('account_deletion_disabled', 'Account deletion is disabled.'),
                [],
                403,
            );
        }

        /** @var \Illuminate\Foundation\Auth\User $user */
        $user = $request->user();

        if ((bool) config('auth_system.account.deletion.require_password', true)) {
            $password = $request->string('password')->toString();

            if (! Hash::check($password, (string) $user->password)) {
                return $this->failure(
                    $this->errKey('account_password_mismatch', 'The provided password is incorrect.'),
                    ['password' => ['The provided password is incorrect.']],
                    422,
                );
            }
        }

        try {
            $entry = $this->deletionService->delete($user, $request->input('reason'));
        } catch (AuthException $e) {
            return $this->failure($this->err($e), [], 403);
        }

        return $this->success(
            $this->msg('account_deleted', 'Account scheduled for deletion.'),
            [
                'scheduled_purge_at' => $entry->scheduled_purge_at?->toIso8601String(),
                'grace_days'         => (int) config('auth_system.account.deletion.grace_days', 30),
                'auto_restore'       => (bool) config('auth_system.account.deletion.auto_restore_on_login', true),
            ],
        );
    }

    /**
     * POST /auth/account/deactivate — Instagram-style pause. Flips the user
     * to "deactivated", revokes every session/token, sends the deactivation
     * email. The user can come back at any time by logging in normally; the
     * login flow auto-reactivates them silently.
     */
    public function deactivate(DeactivateAccountRequest $request): JsonResponse
    {
        if (! (bool) config('auth_system.account.deactivation.enabled', true)
            || ! (bool) config('auth_system.account.deactivation.self_service', true)
        ) {
            return $this->failure(
                $this->errKey('account_deactivation_disabled', 'Account deactivation is currently disabled.'),
                [],
                403,
            );
        }

        /** @var \Illuminate\Foundation\Auth\User $user */
        $user = $request->user();

        if ((bool) config('auth_system.account.deactivation.require_password', true)) {
            $password = $request->string('password')->toString();

            if (! Hash::check($password, (string) $user->password)) {
                return $this->failure(
                    $this->errKey('account_password_mismatch', 'The provided password is incorrect.'),
                    ['password' => ['The provided password is incorrect.']],
                    422,
                );
            }
        }

        try {
            // changeStatus revokes tokens + sessions when leaving "active",
            // dispatches AccountStatusChanged, and writes status_reason.
            $this->statusService->changeStatus(
                $user,
                AccountStatus::DEACTIVATED,
                $request->input('reason'),
                null,
                [
                    'actor_type' => 'user',
                    'actor_id'   => (int) $user->getKey(),
                    'source'     => 'self_deactivate',
                ],
            );
        } catch (AuthException $e) {
            return $this->failure($this->err($e), [], 422);
        }

        if ((bool) config('auth_system.mail.account_notifications_enabled.deactivated', true)) {
            $this->dispatchDeactivatedNotification($user);
        }

        return $this->success(
            $this->msg('account_deactivated', 'Account deactivated. Log in any time to reactivate.'),
            [
                'status'                   => AccountStatus::DEACTIVATED,
                'auto_reactivate_on_login' => (bool) config('auth_system.account.deactivation.auto_reactivate_on_login', true),
            ],
        );
    }

    private function dispatchDeactivatedNotification(\Illuminate\Foundation\Auth\User $user): void
    {
        $class = (string) (config('auth_system.mail.account_deactivated_notification')
            ?: AccountDeactivatedNotification::class);

        if (! class_exists($class)) {
            return;
        }

        try {
            Notification::send($user, new $class());
        } catch (\Throwable) {
            // Deactivation must not fail if the mailer is misconfigured.
        }
    }
}
