<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Joe404\LaravelAuth\Exceptions\PhoneVerificationException;
use Joe404\LaravelAuth\Http\Concerns\ResolvesMessages;
use Joe404\LaravelAuth\Http\Concerns\RespondsWithJson;
use Joe404\LaravelAuth\Http\Requests\PhoneSendOtpRequest;
use Joe404\LaravelAuth\Http\Requests\PhoneVerifyRequest;
use Joe404\LaravelAuth\Services\PhoneVerificationService;

class PhoneVerificationController extends Controller
{
    use ResolvesMessages, RespondsWithJson;

    public function __construct(
        private readonly PhoneVerificationService $phoneService,
    ) {}

    public function send(PhoneSendOtpRequest $request): JsonResponse
    {
        if (! (bool) config('auth_system.phone.enabled', false)) {
            return $this->failure('Phone feature is disabled.', [], 404);
        }

        $user    = $request->user();
        $phone   = $this->phoneService->normalizePhone((string) $request->string('phone'));
        $channel = $request->input('channel');

        try {
            $record = $this->phoneService->sendCode(
                $user?->getKey(),
                $phone,
                \Joe404\LaravelAuth\Models\AuthPhoneOtpCode::PURPOSE_PHONE_VERIFY,
                is_string($channel) ? $channel : null,
            );
        } catch (PhoneVerificationException $e) {
            return $this->failure($this->err($e), [], 422);
        }

        return $this->success('Phone verification code sent.', [
            'channel'    => $record->channel,
            'expires_at' => $record->expires_at?->toIso8601String(),
        ]);
    }

    public function verify(PhoneVerifyRequest $request): JsonResponse
    {
        if (! (bool) config('auth_system.phone.enabled', false)) {
            return $this->failure('Phone feature is disabled.', [], 404);
        }

        $phone = $this->phoneService->normalizePhone((string) $request->string('phone'));
        $code  = (string) $request->string('code');

        try {
            $this->phoneService->verifyCode($phone, $code);
        } catch (PhoneVerificationException $e) {
            return $this->failure($this->err($e), [], 422);
        }

        $user = $request->user();

        if ($user !== null) {
            $this->phoneService->markVerified($user, $phone);
        }

        return $this->success('Phone verified successfully.', [
            'phone'             => $phone,
            'phone_verified_at' => $user?->phone_verified_at?->toIso8601String(),
        ]);
    }
}
