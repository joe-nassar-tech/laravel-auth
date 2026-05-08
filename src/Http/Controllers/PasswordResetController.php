<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Http\Controllers;

use Illuminate\Http\JsonResponse;
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

    public function magicRedirect(string $token, Request $request): JsonResponse
    {
        if (! $request->hasValidSignature()) {
            return $this->failure('Invalid or expired reset link.', [], 422);
        }

        try {
            $resetToken = $this->authService->validateResetMagicLink($token);
        } catch (OtpExpiredException $e) {
            return $this->failure($e->getMessage(), [], 422);
        } catch (OtpInvalidException $e) {
            return $this->failure($e->getMessage(), [], 422);
        }

        return $this->success(
            'Link validated. Submit your new password using the reset_token.',
            ['reset_token' => $resetToken],
        );
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
