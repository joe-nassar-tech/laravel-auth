<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Joe404\LaravelAuth\Exceptions\AuthException;
use Joe404\LaravelAuth\Http\Concerns\ResolvesMessages;
use Joe404\LaravelAuth\Http\Concerns\RespondsWithJson;
use Joe404\LaravelAuth\Http\Requests\ChangeStatusRequest;
use Joe404\LaravelAuth\Services\AccountStatusService;
use Joe404\LaravelAuth\Support\AccountStatus;

class UserStatusController extends Controller
{
    use ResolvesMessages, RespondsWithJson;

    public function __construct(
        private readonly AccountStatusService $statusService,
    ) {}

    public function show(int $id): JsonResponse
    {
        $user = $this->resolveUser($id);

        if ($user === null) {
            return $this->failure('User not found.', [], 404);
        }

        // current() runs the lazy auto-unban check, so reading status_expires_at
        // afterwards reflects the post-revert state.
        $status    = $this->statusService->current($user);
        $expiresAt = $user->status_expires_at ?? null;

        return $this->success('User status retrieved.', [
            'user_id'           => $user->getKey(),
            'status'            => $status,
            'status_changed_at' => $user->status_changed_at ?? null,
            'status_reason'     => $user->status_reason ?? null,
            'status_expires_at' => $expiresAt instanceof \Illuminate\Support\Carbon
                ? $expiresAt->toIso8601String()
                : $expiresAt,
            'allowed'           => AccountStatus::allowed(),
        ]);
    }

    public function update(ChangeStatusRequest $request, int $id): JsonResponse
    {
        $user = $this->resolveUser($id);

        if ($user === null) {
            return $this->failure('User not found.', [], 404);
        }

        // #14 — admin action guardrails (opt-in via
        // account.status.admin_actions.enforce_role_hierarchy; default off so
        // existing admin clients are unaffected). When enabled, an actor may
        // only act on a strictly lower-ranked account (never a peer, a
        // higher-ranked account, or themselves), and the destructive `deleted`
        // status must go through the dedicated deletion flow.
        if ((bool) config('auth_system.account.status.admin_actions.enforce_role_hierarchy', false)) {
            if ($request->string('status')->toString() === AccountStatus::DELETED) {
                return $this->failure(
                    'Use the account deletion flow to delete an account; it cannot be set via the status endpoint.',
                    [],
                    422,
                );
            }

            $denial = $this->hierarchyDenial($request->user(), $user);

            if ($denial !== null) {
                return $this->failure($denial, [], 403);
            }
        }

        try {
            $this->statusService->changeStatus(
                $user,
                $request->string('status')->toString(),
                $request->input('reason'),
                $request->resolveExpiresAt(),
                [
                    'actor'   => $request->user(),
                    'comment' => $request->input('comment'),
                    'source'  => 'admin_endpoint',
                ],
            );
        } catch (AuthException $e) {
            return $this->failure($this->err($e), [], 422);
        }

        $user->refresh();
        $expiresAt = $user->status_expires_at ?? null;

        return $this->success(
            $this->msg('account_status_updated', 'Account status updated.'),
            [
                'user_id'           => $user->getKey(),
                'status'            => $this->statusService->current($user),
                'status_expires_at' => $expiresAt instanceof \Illuminate\Support\Carbon
                    ? $expiresAt->toIso8601String()
                    : $expiresAt,
            ],
        );
    }

    /**
     * Returns a human-readable denial reason when the actor may not change the
     * target's status under the role hierarchy, or null when it is permitted.
     */
    private function hierarchyDenial(
        \Illuminate\Foundation\Auth\User $actor,
        \Illuminate\Foundation\Auth\User $target,
    ): ?string {
        $cfg        = (array) config('auth_system.account.status.admin_actions', []);
        $allowSelf  = (bool) ($cfg['allow_self_action'] ?? false);
        $allowEqual = (bool) ($cfg['allow_equal_rank'] ?? false);
        $isSelf     = (string) $actor->getKey() === (string) $target->getKey();

        if ($isSelf) {
            return $allowSelf ? null : 'You cannot change your own account status.';
        }

        $actorRank  = $this->rankOf($actor);
        $targetRank = $this->rankOf($target);

        if ($targetRank > $actorRank) {
            return 'You cannot change the status of a higher-privileged account.';
        }

        if ($targetRank === $actorRank && ! $allowEqual) {
            return 'You cannot change the status of an account at your own privilege level.';
        }

        return null;
    }

    /**
     * Highest configured rank across the user's roles. Roles absent from
     * account.status.admin_actions.role_ranks count as 0.
     */
    private function rankOf(\Illuminate\Foundation\Auth\User $user): int
    {
        /** @var array<string,int> $ranks */
        $ranks = (array) config('auth_system.account.status.admin_actions.role_ranks', []);

        if (! method_exists($user, 'getRoleNames')) {
            return 0;
        }

        $max = 0;

        foreach ($user->getRoleNames() as $role) {
            $max = max($max, (int) ($ranks[$role] ?? 0));
        }

        return $max;
    }

    private function resolveUser(int $id): ?\Illuminate\Foundation\Auth\User
    {
        /** @var class-string<\Illuminate\Foundation\Auth\User> $userModel */
        $userModel = config('auth.providers.users.model', \App\Models\User::class);

        return $userModel::find($id);
    }
}
