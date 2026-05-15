<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Joe404\LaravelAuth\Exceptions\AccountInactiveException;
use Joe404\LaravelAuth\Exceptions\AuthException;
use Joe404\LaravelAuth\Http\Concerns\ResolvesMessages;
use Joe404\LaravelAuth\Http\Concerns\RespondsWithJson;
use Joe404\LaravelAuth\Services\SocialAuthService;

class SocialAuthController extends Controller
{
    use ResolvesMessages, RespondsWithJson;

    public function __construct(
        private readonly SocialAuthService $socialAuthService,
    ) {}

    public function redirect(Request $request): JsonResponse|RedirectResponse
    {
        try {
            $url = $this->socialAuthService->redirectUrl('google', $request);
        } catch (AuthException $e) {
            return $this->failure($this->err($e), [], 403);
        }

        // SPA / mobile clients call this via JSON-XHR and want the URL string.
        // Plain browser hits get a real 302 redirect.
        if ($request->wantsJson()) {
            return $this->success('Redirect URL generated.', ['redirect_url' => $url]);
        }

        return redirect()->away($url);
    }

    public function callback(Request $request): JsonResponse
    {
        try {
            $result = $this->socialAuthService->handleCallback('google', $request);
        } catch (AccountInactiveException $e) {
            return $this->failure($this->err($e), [], 403);
        } catch (AuthException $e) {
            return $this->failure($this->err($e), [], 401);
        }

        if (($result['status'] ?? null) === 'requires_link_confirmation') {
            return $this->success(
                'An email was sent to confirm linking your account. Please click the link to finish signing in.',
                ['email' => $result['email'] ?? null],
                202,
            );
        }

        unset($result['status']);

        return $this->success('Logged in with Google successfully.', $result);
    }

    public function confirmLink(string $provider, string $token, Request $request): JsonResponse|RedirectResponse
    {
        if (! $request->hasValidSignature()) {
            return $this->failure($this->errKey('social_link_token_invalid', 'Invalid or expired confirmation link.'), [], 422);
        }

        try {
            $result = $this->socialAuthService->confirmLink($token, $request);
        } catch (AccountInactiveException $e) {
            return $this->failure($this->err($e), [], 403);
        } catch (AuthException $e) {
            return $this->failure($this->err($e), [], 401);
        }

        $frontendUrl = (string) config('auth_system.social.frontend_url', '');

        if ($frontendUrl !== '' && ! $request->wantsJson()) {
            $params = ['linked' => 'true', 'provider' => $provider];

            if (! empty($result['token'])) {
                $params['token']         = $result['token'];
                $params['refresh_token'] = $result['refresh_token'];
            }

            return redirect()->away(rtrim($frontendUrl, '/') . '?' . http_build_query($params));
        }

        return $this->success('Account linked. Logged in successfully.', $result);
    }
}
