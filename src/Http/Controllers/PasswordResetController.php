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
use Joe404\LaravelAuth\Http\Concerns\RespondsWithJson;
use Joe404\LaravelAuth\Http\Requests\PasswordForgotRequest;
use Joe404\LaravelAuth\Http\Requests\PasswordResetConfirmRequest;
use Joe404\LaravelAuth\Http\Requests\PasswordResetOtpRequest;
use Joe404\LaravelAuth\Services\AuthService;

class PasswordResetController extends Controller
{
    use RespondsWithJson;

    public function __construct(
        private readonly AuthService $authService,
    ) {}

    public function forgot(PasswordForgotRequest $request): JsonResponse
    {
        // Always same response — prevent email enumeration
        $this->authService->forgotPassword($request->validated('email'));

        return $this->success(
            'If that email is registered, you will receive reset instructions shortly.',
        );
    }

    public function resetWithOtp(PasswordResetOtpRequest $request): JsonResponse
    {
        try {
            $this->authService->resetPasswordWithOtp(
                $request->validated('email'),
                $request->validated('otp'),
                $request->validated('password'),
            );
        } catch (OtpExpiredException $e) {
            return $this->failure($e->getMessage(), [], 422);
        } catch (OtpInvalidException $e) {
            return $this->failure($e->getMessage(), [], 422);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->failure('User not found.', [], 422);
        }

        return $this->success('Password reset successfully. Please log in with your new password.');
    }

    public function magicRedirect(string $token, Request $request): JsonResponse|RedirectResponse
    {
        $isFrontend = config('auth_system.verification.magic_link_target') === 'frontend';

        if (! $isFrontend && ! $request->hasValidSignature()) {
            return $this->resetFailure($request, 'Invalid or expired reset link.', 'invalid_link');
        }

        try {
            $resetToken = $this->authService->validateResetMagicLink($token);
        } catch (OtpExpiredException $e) {
            return $this->resetFailure($request, $e->getMessage(), 'expired_link');
        } catch (OtpInvalidException $e) {
            return $this->resetFailure($request, $e->getMessage(), 'invalid_link');
        }

        $frontendUrl = (string) config('auth_system.verification.frontend_reset_url', '');

        if ($frontendUrl !== '' && ! $request->wantsJson()) {
            return redirect()->away(
                rtrim($frontendUrl, '/') . '?reset_token=' . urlencode($resetToken),
            );
        }

        return $this->success(
            'Link validated. Submit your new password using the reset_token.',
            ['reset_token' => $resetToken],
        );
    }

    private function resetFailure(Request $request, string $message, string $code): JsonResponse|RedirectResponse
    {
        $frontendUrl = (string) config('auth_system.verification.frontend_reset_url', '');

        if ($frontendUrl !== '' && ! $request->wantsJson()) {
            return redirect()->away(
                rtrim($frontendUrl, '/') . '?error=' . urlencode($code),
            );
        }

        return $this->failure($message, [], 422);
    }

    public function confirm(PasswordResetConfirmRequest $request): JsonResponse
    {
        try {
            $this->authService->resetPasswordWithToken(
                $request->validated('reset_token'),
                $request->validated('password'),
            );
        } catch (AuthException $e) {
            return $this->failure($e->getMessage(), [], 422);
        }

        return $this->success('Password reset successfully. Please log in with your new password.');
    }
}
