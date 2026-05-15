<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Joe404\LaravelAuth\Http\Concerns\ResolvesMessages;
use Joe404\LaravelAuth\Http\Concerns\RespondsWithJson;
use Joe404\LaravelAuth\Services\SessionService;

class SessionController extends Controller
{
    use ResolvesMessages, RespondsWithJson;

    public function __construct(
        private readonly SessionService $sessionService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $sessions = $this->sessionService->listForUser($request->user(), $request);

        return $this->success($this->msg('sessions_retrieved', 'Sessions retrieved.'), ['sessions' => $sessions]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        try {
            $this->sessionService->delete($request->user(), $id);

            return $this->success($this->msg('session_terminated', 'Session terminated.'));
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            return $this->failure($this->err($e, 'session_not_found'), [], 404);
        }
    }
}
