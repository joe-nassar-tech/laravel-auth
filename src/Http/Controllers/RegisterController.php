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
use Joe404\LaravelAuth\Http\Requests\RegisterRequest;
use Joe404\LaravelAuth\Http\Requests\VerifyOtpRequest;
use Joe404\LaravelAuth\Services\AuthService;

class RegisterController extends Controller
{
    use RespondsWithJson;

    public function __construct(
        private readonly AuthService $authService,
    ) {}

    public function initiate(RegisterRequest $request): JsonResponse
    {
        try {
            $result = $this->authService->initiateRegistration(
                $request->string('email')->toString(),
                $request->string('password')->toString(),
            );
        } catch (\DomainException $e) {
            return $this->failure($e->getMessage(), [], 409);
        } catch (AuthException $e) {
            return $this->failure($e->getMessage());
        }

        return $this->success('Verification sent. Please check your email.', $result, 201);
    }

    public function verifyOtp(VerifyOtpRequest $request): JsonResponse
    {
        try {
            $result = $this->authService->completeRegistrationWithOtp(
                $request->string('email')->toString(),
                $request->string('otp')->toString(),
            );
        } catch (OtpInvalidException $e) {
            return $this->failure($e->getMessage(), [], 422);
        } catch (OtpExpiredException $e) {
            return $this->failure($e->getMessage(), [], 422);
        }

        return $this->success('Registration complete.', $result, 201);
    }

    public function verifyMagic(string $token, Request $request): JsonResponse
    {
        if (! $request->hasValidSignature()) {
            return $this->failure('Invalid or expired verification link.', [], 422);
        }

        try {
            $result = $this->authService->completeRegistrationWithMagicLink($token);
        } catch (OtpInvalidException $e) {
            return $this->failure($e->getMessage(), [], 422);
        } catch (OtpExpiredException $e) {
            return $this->failure($e->getMessage(), [], 422);
        }

        return $this->success('Registration complete.', $result, 201);
    }
}
