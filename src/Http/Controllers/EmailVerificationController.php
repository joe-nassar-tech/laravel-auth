<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Joe404\LaravelAuth\Http\Concerns\ResolvesMessages;
use Joe404\LaravelAuth\Http\Concerns\RespondsWithJson;
use Joe404\LaravelAuth\Http\Requests\ResendVerificationRequest;
use Joe404\LaravelAuth\Services\OtpService;

class EmailVerificationController extends Controller
{
    use ResolvesMessages, RespondsWithJson;

    public function __construct(
        private readonly OtpService $otpService,
    ) {}

    public function resend(ResendVerificationRequest $request): JsonResponse
    {
        $email     = strtolower(trim($request->validated('email')));
        $tempToken = Str::uuid()->toString();

        // Only resend if there is a pending pre-registration in cache. When
        // there isn't, we still return a structurally identical response so
        // an outsider cannot probe which emails have pending registrations.
        if (Cache::has("auth:pending:{$email}")) {
            $method = (string) config('auth_system.verification.method', 'both');

            match ($method) {
                'both'       => $this->otpService->sendCombined($email, 'email_verify', 'magic_link_verify', $tempToken),
                'magic_link' => $this->otpService->sendMagicLink($email, 'magic_link_verify', $tempToken),
                default      => $this->otpService->sendOtp($email, 'email_verify', $tempToken),
            };
        }

        // Always same response shape — prevents enumeration of pending
        // registrations. SPAs should always update their stored temp_token
        // from this response so the realtime channel subscription tracks the
        // currently-valid OTP records (resend invalidates previous OTPs and
        // creates new ones with this new temp_token).
        return $this->success(
            $this->msg('verification_resent', 'If your email is pending verification, new instructions have been sent.'),
            ['temp_token' => $tempToken],
        );
    }
}
