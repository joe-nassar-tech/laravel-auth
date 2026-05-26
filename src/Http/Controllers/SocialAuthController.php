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
use Joe404\LaravelAuth\Http\Requests\SocialRegisterCompleteRequest;
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

        // v2.6 — brand-new user whose profile needs the host's required
        // fields before the account can be created. Return the completion
        // token + prefill so the frontend can show an onboarding form.
        if (($result['status'] ?? null) === 'requires_profile_completion') {
            return $this->success(
                $this->msg('social_profile_completion_required', 'A few more details are needed to finish creating your account.'),
                [
                    'requires_profile_completion' => true,
                    'completion_token'            => $result['completion_token'] ?? null,
                    'prefill'                     => $result['prefill'] ?? [],
                ],
                202,
            );
        }

        // #1 — the social user has 2FA enrolled and the device isn't trusted:
        // no token is issued until they pass the 2FA challenge, same as login.
        if (($result['status'] ?? null) === 'requires_2fa') {
            return $this->twoFactorChallengeResponse($result);
        }

        unset($result['status']);

        return $this->success('Logged in with Google successfully.', $result);
    }

    /**
     * Render the standard 2FA-challenge envelope returned by the social flows
     * when the user must complete /auth/2fa/challenge before a token is issued.
     *
     * @param array<string,mixed> $result
     */
    private function twoFactorChallengeResponse(array $result): JsonResponse
    {
        return $this->success(
            $this->msg('two_factor_challenge_required', '2FA verification required.'),
            [
                'requires_2fa'      => true,
                'challenge_token'   => $result['challenge_token'] ?? null,
                'method'            => $result['method'] ?? null,
                'available_methods' => $result['available_methods'] ?? [],
                'masked_target'     => $result['masked_target'] ?? null,
                'expires_in'        => $result['expires_in'] ?? null,
            ],
        );
    }

    /**
     * Finalize a social sign-up for a brand-new user by collecting the host's
     * required registration fields. Only reachable when
     * `social.profile_completion.enabled` is true.
     */
    public function complete(SocialRegisterCompleteRequest $request): JsonResponse
    {
        if (! (bool) config('auth_system.social.profile_completion.enabled', false)) {
            return $this->failure('Social profile completion is not enabled.', [], 404);
        }

        try {
            $result = $this->socialAuthService->finalizeSocialRegistration(
                (string) $request->string('completion_token'),
                $request->validated(),
                $request,
            );
        } catch (AccountInactiveException $e) {
            return $this->failure($this->err($e), [], 403);
        } catch (AuthException $e) {
            return $this->failure($this->err($e), [], 422);
        }

        if (($result['status'] ?? null) === 'requires_2fa') {
            return $this->twoFactorChallengeResponse($result);
        }

        unset($result['status']);

        return $this->success($this->msg('register_complete', 'Account created. Logged in successfully.'), $result);
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

        // If the linked account has 2FA, finish via the challenge (no token in
        // the redirect yet) rather than redirecting with credentials.
        if (($result['status'] ?? null) === 'requires_2fa') {
            return $this->twoFactorChallengeResponse($result);
        }

        $frontendUrl = (string) config('auth_system.social.frontend_url', '');

        if ($frontendUrl !== '' && ! $request->wantsJson()) {
            // Non-sensitive status flags can stay in the query string; the
            // access/refresh tokens go in the URL FRAGMENT (#) so they never
            // reach server/proxy logs or the Referer header. The SPA reads the
            // tokens from window.location.hash.
            $query = http_build_query(['linked' => 'true', 'provider' => $provider]);

            $url = rtrim($frontendUrl, '/') . '?' . $query;

            if (! empty($result['token'])) {
                $url .= '#' . http_build_query([
                    'token'         => $result['token'],
                    'refresh_token' => $result['refresh_token'],
                ]);
            }

            return redirect()->away($url);
        }

        return $this->success('Account linked. Logged in successfully.', $result);
    }
}
