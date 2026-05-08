<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Joe404\LaravelAuth\Http\Concerns\RespondsWithJson;
use Joe404\LaravelAuth\Services\AuthService;

class LogoutController extends Controller
{
    use RespondsWithJson;

    public function __construct(
        private readonly AuthService $authService,
    ) {}

    public function logout(Request $request): JsonResponse
    {
        $this->authService->logout($request);

        return $this->success('Logged out successfully.');
    }

    public function logoutAll(Request $request): JsonResponse
    {
        $this->authService->logoutAll($request->user());

        return $this->success('Logged out of all sessions.');
    }
}
