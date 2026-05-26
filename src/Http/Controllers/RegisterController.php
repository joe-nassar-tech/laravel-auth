<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Joe404\LaravelAuth\Exceptions\AuthException;
use Joe404\LaravelAuth\Exceptions\OtpExpiredException;
use Joe404\LaravelAuth\Exceptions\OtpInvalidException;
use Joe404\LaravelAuth\Http\Concerns\ResolvesMessages;
use Joe404\LaravelAuth\Http\Concerns\RespondsWithJson;
use Joe404\LaravelAuth\Http\Requests\RegisterCompleteRequest;
use Joe404\LaravelAuth\Http\Requests\RegisterRequest;
use Joe404\LaravelAuth\Http\Requests\VerifyOtpRequest;
use Joe404\LaravelAuth\Services\AuthService;

class RegisterController extends Controller
{
    use ResolvesMessages, RespondsWithJson;

    public function __construct(
        private readonly AuthService $authService,
    ) {}

    public function initiate(RegisterRequest $request): JsonResponse
    {
        $extraFields = collect($request->validated())
            ->except(['email'])
            ->all();

        try {
            $result = $this->authService->initiateRegistration(
                $request->string('email')->toString(),
                $extraFields,
            );
        } catch (\DomainException $e) {
            return $this->failure($this->err($e, 'email_already_registered'), [], 409);
        } catch (AuthException $e) {
            return $this->failure($this->err($e));
        }

        return $this->success($this->msg('register_initiated', 'Verification sent. Please check your email.'), $result, 201);
    }

    public function verifyOtp(VerifyOtpRequest $request): JsonResponse
    {
        try {
            $result = $this->authService->completeRegistrationWithOtp(
                $request->string('email')->toString(),
                $request->string('otp')->toString(),
            );
        } catch (OtpInvalidException $e) {
            return $this->failure($this->err($e), [], 422);
        } catch (OtpExpiredException $e) {
            return $this->failure($this->err($e), [], 422);
        } catch (\RuntimeException $e) {
            return $this->failure($this->err($e, 'registration_session_expired'), [], 422);
        }

        return $this->success($this->msg('register_verified', 'Email verified. Please set your password.'), $result);
    }

    public function verifyMagic(string $token, Request $request): JsonResponse|RedirectResponse
    {
        $isFrontend = config('auth_system.verification.magic_link_target') === 'frontend';

        if (! $isFrontend && ! $request->hasValidSignature()) {
            return $this->magicLinkFailure(
                $request,
                $this->errKey('magic_link_invalid', 'Invalid or expired verification link.'),
                'invalid_link',
            );
        }

        try {
            $result = $this->authService->completeRegistrationWithMagicLink($token);
        } catch (OtpInvalidException $e) {
            return $this->magicLinkFailure($request, $this->err($e), 'invalid_link');
        } catch (OtpExpiredException $e) {
            return $this->magicLinkFailure($request, $this->err($e), 'expired_link');
        } catch (\RuntimeException $e) {
            return $this->magicLinkFailure($request, $this->err($e, 'registration_session_expired'), 'session_expired');
        }

        // Browser hits get a 302 to the frontend with the completion_token in
        // the URL FRAGMENT (#), not the query string. Fragments are never sent
        // to the server, so they don't land in access logs, proxy logs, or the
        // Referer header. The SPA reads it from window.location.hash. JSON
        // clients (or hosts without a configured frontend URL) get the JSON
        // envelope as before.
        $frontendUrl = (string) config('auth_system.verification.frontend_verify_url', '');

        if ($frontendUrl !== '' && ! $request->wantsJson()) {
            return redirect()->away(
                rtrim($frontendUrl, '/') . '#completion_token=' . urlencode($result['completion_token']),
            );
        }

        return $this->success($this->msg('register_verified', 'Email verified. Please set your password.'), $result);
    }

    private function magicLinkFailure(Request $request, string $message, string $code): JsonResponse|RedirectResponse
    {
        $frontendUrl = (string) config('auth_system.verification.frontend_verify_url', '');

        if ($frontendUrl !== '' && ! $request->wantsJson()) {
            return redirect()->away(
                rtrim($frontendUrl, '/') . '?error=' . urlencode($code),
            );
        }

        return $this->failure($message, [], 422);
    }

    public function complete(RegisterCompleteRequest $request): JsonResponse
    {
        try {
            $result = $this->authService->finalizeRegistration(
                $request->string('completion_token')->toString(),
                $request->string('password')->toString(),
                $request,
            );
        } catch (AuthException $e) {
            return $this->failure($this->err($e), [], 422);
        } catch (\DomainException $e) {
            return $this->failure($this->err($e, 'email_already_registered'), [], 409);
        }

        return $this->success($this->msg('register_complete', 'Registration complete.'), $result, 201);
    }
}
