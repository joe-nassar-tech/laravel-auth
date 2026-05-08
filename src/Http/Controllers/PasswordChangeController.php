<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Joe404\LaravelAuth\Exceptions\AuthException;
use Joe404\LaravelAuth\Http\Concerns\RespondsWithJson;
use Joe404\LaravelAuth\Http\Requests\PasswordChangeRequest;
use Joe404\LaravelAuth\Services\AuthService;

class PasswordChangeController extends Controller
{
    use RespondsWithJson;

    public function __construct(
        private readonly AuthService $authService,
    ) {}

    public function change(PasswordChangeRequest $request): JsonResponse
    {
        try {
            $this->authService->changePassword(
                user:            $request->user(),
                currentPassword: $request->validated('current_password'),
                newPassword:     $request->validated('new_password'),
                logoutAll:       (bool) $request->validated('logout_all', false),
                request:         $request,
            );
        } catch (AuthException $e) {
            return $this->failure($e->getMessage(), [], 422);
        }

        return $this->success('Password changed successfully.');
    }
}
