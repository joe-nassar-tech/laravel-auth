<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Joe404\LaravelAuth\Http\Concerns\RespondsWithJson;
use Joe404\LaravelAuth\Http\Requests\ResendVerificationRequest;
use Joe404\LaravelAuth\Services\OtpService;

class EmailVerificationController extends Controller
{
    use RespondsWithJson;

    public function __construct(
        private readonly OtpService $otpService,
    ) {}

    public function resend(ResendVerificationRequest $request): JsonResponse
    {
        $email = strtolower(trim($request->validated('email')));

        // Only resend if there is a pending pre-registration in cache
        // (the hashed password stored during initiateRegistration)
        if (Cache::has("auth:pending:{$email}")) {
            $method    = (string) config('auth_system.verification.method', 'both');
            $tempToken = Str::uuid()->toString();

            if ($method === 'otp' || $method === 'both') {
                $this->otpService->sendOtp($email, 'email_verify', $tempToken);
            }
            if ($method === 'magic_link' || $method === 'both') {
                $this->otpService->sendMagicLink($email, 'magic_link_verify', $tempToken);
            }
        }

        // Always same response — prevents enumeration of pending registrations
        return $this->success(
            'If your email is pending verification, new instructions have been sent.',
            ['temp_token_hint' => 'Subscribe to the new temp_token returned if you re-initiated registration.'],
        );
    }
}
