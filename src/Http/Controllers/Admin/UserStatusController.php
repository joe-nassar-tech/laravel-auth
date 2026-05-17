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

    private function resolveUser(int $id): ?\Illuminate\Foundation\Auth\User
    {
        /** @var class-string<\Illuminate\Foundation\Auth\User> $userModel */
        $userModel = config('auth.providers.users.model', \App\Models\User::class);

        return $userModel::find($id);
    }
}
