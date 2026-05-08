<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Joe404\LaravelAuth\Http\Concerns\RespondsWithJson;
use Joe404\LaravelAuth\Services\SessionService;

class SessionController extends Controller
{
    use RespondsWithJson;

    public function __construct(
        private readonly SessionService $sessionService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $sessions = $this->sessionService->listForUser($request->user(), $request);

        return $this->success('Sessions retrieved.', ['sessions' => $sessions]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        try {
            $this->sessionService->delete($request->user(), $id);

            return $this->success('Session terminated.');
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            return $this->failure($e->getMessage(), [], 404);
        }
    }
}
