<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Joe404\LaravelAuth\Exceptions\AuthException;
use Joe404\LaravelAuth\Exceptions\TokenExpiredException;
use Joe404\LaravelAuth\Http\Concerns\ResolvesMessages;
use Joe404\LaravelAuth\Http\Concerns\RespondsWithJson;
use Joe404\LaravelAuth\Services\AuthService;

class TokenRefreshController extends Controller
{
    use ResolvesMessages, RespondsWithJson;

    public function __construct(
        private readonly AuthService $authService,
    ) {}

    public function refresh(Request $request): JsonResponse
    {
        $refreshToken = $request->string('refresh_token')->toString();

        if ($refreshToken === '') {
            return $this->failure('refresh_token is required.', [], 422);
        }

        try {
            $result = $this->authService->refreshToken($refreshToken, $request);
        } catch (TokenExpiredException $e) {
            return $this->failure($this->err($e), [], 401);
        } catch (AuthException $e) {
            return $this->failure($this->err($e), [], 401);
        }

        return $this->success('Token refreshed successfully.', $result);
    }
}
