<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Joe404\LaravelAuth\Exceptions\AccountInactiveException;
use Joe404\LaravelAuth\Exceptions\AuthException;
use Joe404\LaravelAuth\Http\Concerns\RespondsWithJson;
use Joe404\LaravelAuth\Services\SocialAuthService;

class SocialAuthController
{
    use RespondsWithJson;

    public function __construct(
        private readonly SocialAuthService $socialAuthService,
    ) {}

    public function redirect(): JsonResponse
    {
        try {
            $url = $this->socialAuthService->redirectUrl('google');
        } catch (AuthException $e) {
            return $this->failure($e->getMessage(), [], 403);
        }

        return $this->success('Redirect URL generated.', ['redirect_url' => $url]);
    }

    public function callback(Request $request): JsonResponse
    {
        try {
            $result = $this->socialAuthService->handleCallback('google', $request);
        } catch (AccountInactiveException $e) {
            return $this->failure($e->getMessage(), [], 403);
        } catch (AuthException $e) {
            return $this->failure($e->getMessage(), [], 401);
        }

        return $this->success('Logged in with Google successfully.', $result);
    }
}
