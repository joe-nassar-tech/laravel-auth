<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Joe404\LaravelAuth\Http\Concerns\ResolvesMessages;
use Joe404\LaravelAuth\Http\Concerns\RespondsWithJson;
use Joe404\LaravelAuth\Http\Requests\AddUserNoteRequest;
use Joe404\LaravelAuth\Models\AccountStatusLog;
use Joe404\LaravelAuth\Services\AccountAuditService;

/**
 * Admin-only endpoints for the account audit log.
 *
 * - GET  /auth/admin/users/{id}/status/history → paginated history
 * - POST /auth/admin/users/{id}/notes          → free-form admin note
 *
 * Both are gated by the same role as the status endpoint
 * (`account.status.admin_ability`) and each has its own enable flag so a
 * host can turn either one off independently.
 */
class UserAuditController extends Controller
{
    use ResolvesMessages, RespondsWithJson;

    public function __construct(
        private readonly AccountAuditService $audit,
    ) {}

    public function history(Request $request, int $id): JsonResponse
    {
        if (! (bool) config('auth_system.account.audit.enabled', true)
            || ! (bool) config('auth_system.account.audit.history.enabled', true)
        ) {
            return $this->failure('History endpoint is disabled.', [], 404);
        }

        // Bounded pagination so a curious admin cannot ask for 10k rows.
        $defaultPerPage = (int) config('auth_system.account.audit.history.default_per_page', 20);
        $maxPerPage     = (int) config('auth_system.account.audit.history.max_per_page', 100);

        $perPage = max(1, min((int) $request->integer('per_page', $defaultPerPage), $maxPerPage));

        $query = AccountStatusLog::query()
            ->where('user_id', $id)
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($actorType = $request->string('actor_type')->toString()) {
            $query->where('actor_type', $actorType);
        }
        if ($action = $request->string('action')->toString()) {
            $query->where('action', $action);
        }
        if ($from = $request->string('from')->toString()) {
            try {
                $query->where('created_at', '>=', \Illuminate\Support\Carbon::parse($from));
            } catch (\Throwable) {}
        }
        if ($to = $request->string('to')->toString()) {
            try {
                $query->where('created_at', '<=', \Illuminate\Support\Carbon::parse($to));
            } catch (\Throwable) {}
        }

        $page = $query->paginate($perPage);

        return $this->success('Audit history retrieved.', [
            'items'        => $page->items(),
            'page'         => $page->currentPage(),
            'per_page'     => $page->perPage(),
            'total'        => $page->total(),
            'last_page'    => $page->lastPage(),
        ]);
    }

    public function addNote(AddUserNoteRequest $request, int $id): JsonResponse
    {
        if (! (bool) config('auth_system.account.audit.enabled', true)
            || ! (bool) config('auth_system.account.audit.notes.enabled', true)
        ) {
            return $this->failure('Notes endpoint is disabled.', [], 404);
        }

        // Best-effort 404 if the target user doesn't exist — keeps the API
        // honest without doing extra work elsewhere.
        /** @var class-string<\Illuminate\Foundation\Auth\User> $userModel */
        $userModel = config('auth.providers.users.model', \App\Models\User::class);
        if (! $userModel::query()->whereKey($id)->exists()) {
            return $this->failure('User not found.', [], 404);
        }

        $entry = $this->audit->logNote(
            $id,
            $request->string('comment')->toString(),
            $request->input('reason'),
            ['actor' => $request->user(), 'source' => 'admin_note'],
        );

        if ($entry === null) {
            return $this->failure('Failed to record note.', [], 500);
        }

        return $this->success('Note recorded.', ['id' => $entry->id], 201);
    }
}
