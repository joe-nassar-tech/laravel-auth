<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Joe404\LaravelAuth\Exceptions\AccountInactiveException;
use Joe404\LaravelAuth\Exceptions\AuthException;
use Joe404\LaravelAuth\Exceptions\EmailNotVerifiedException;
use Joe404\LaravelAuth\Http\Concerns\ResolvesMessages;
use Joe404\LaravelAuth\Http\Concerns\RespondsWithJson;
use Joe404\LaravelAuth\Http\Requests\LoginRequest;
use Joe404\LaravelAuth\Services\AuthService;

class LoginController extends Controller
{
    use ResolvesMessages, RespondsWithJson;

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
            return $this->failure($this->err($e), [], 403);
        } catch (EmailNotVerifiedException $e) {
            return $this->failure($this->err($e), [], 403);
        } catch (AuthException $e) {
            return $this->failure($this->err($e), [], 401);
        }

        // v2.6 — 2FA gate: when AuthService::login returns requires_2fa it
        // means credentials checked out but the user must complete a 2FA
        // challenge before any token is issued.
        if (($result['requires_2fa'] ?? false) === true) {
            return $this->success(
                $this->msg('two_factor_challenge_required', '2FA verification required.'),
                $result,
            );
        }

        return $this->success($this->msg('login_success', 'Logged in successfully.'), $result);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var \Illuminate\Foundation\Auth\User $user */
        $user = $request->user();

        return $this->success($this->msg('me_retrieved', 'User retrieved.'), $this->authService->me($user));
    }
}
