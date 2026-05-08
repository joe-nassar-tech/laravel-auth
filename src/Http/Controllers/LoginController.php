<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Joe404\LaravelAuth\Exceptions\AccountInactiveException;
use Joe404\LaravelAuth\Exceptions\AuthException;
use Joe404\LaravelAuth\Exceptions\EmailNotVerifiedException;
use Joe404\LaravelAuth\Http\Concerns\RespondsWithJson;
use Joe404\LaravelAuth\Http\Requests\LoginRequest;
use Joe404\LaravelAuth\Services\AuthService;

class LoginController extends Controller
{
    use RespondsWithJson;

    public function __construct(
        private readonly AuthService $authService,
    ) {}

    public function login(LoginRequest $request): JsonResponse
    {
        try {
            $result = $this->authService->login(
                $request->string('email')->toString(),
                $request->string('password')->toString(),
                $request,
            );
        } catch (AccountInactiveException $e) {
            return $this->failure($e->getMessage(), [], 403);
        } catch (EmailNotVerifiedException $e) {
            return $this->failure($e->getMessage(), [], 403);
        } catch (AuthException $e) {
            return $this->failure($e->getMessage(), [], 401);
        }

        return $this->success('Logged in successfully.', $result);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var \Illuminate\Foundation\Auth\User $user */
        $user = $request->user();

        return $this->success('User retrieved.', $this->authService->me($user));
    }
}
