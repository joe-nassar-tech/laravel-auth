<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Joe404\LaravelAuth\Exceptions\AuthException;
use Joe404\LaravelAuth\Http\Concerns\ResolvesMessages;
use Joe404\LaravelAuth\Http\Concerns\RespondsWithJson;
use Joe404\LaravelAuth\Http\Requests\TwoFactorChallengeRequest;
use Joe404\LaravelAuth\Services\AuthService;
use Joe404\LaravelAuth\Services\TwoFactorChallengeService;

class TwoFactorChallengeController extends Controller
{
    use ResolvesMessages, RespondsWithJson;

    public function __construct(
        private readonly AuthService $authService,
        private readonly TwoFactorChallengeService $challenge,
    ) {}

    public function verify(TwoFactorChallengeRequest $request): JsonResponse
    {
        try {
            $result = $this->authService->completeTwoFactorChallenge(
                (string) $request->string('challenge_token'),
                (string) $request->string('code'),
                $request->input('method'),
                (bool) $request->boolean('trust_device'),
                $request,
            );
        } catch (AuthException $e) {
            return $this->failure($this->err($e), [], 401);
        }

        return $this->success($this->msg('two_factor_verified', '2FA verified. Login complete.'), $result);
    }

    public function switch(Request $request): JsonResponse
    {
        $data = $request->validate([
            'challenge_token' => ['required', 'string', 'size:36'],
            'method'          => ['required', Rule::in(['totp', 'email', 'sms'])],
        ]);

        try {
            $payload = $this->challenge->switchMethod(
                (string) $data['challenge_token'],
                (string) $data['method'],
            );
        } catch (AuthException $e) {
            return $this->failure($this->err($e), [], 422);
        }

        return $this->success('2FA method switched. New code sent.', $payload);
    }

    public function resend(Request $request): JsonResponse
    {
        $data = $request->validate([
            'challenge_token' => ['required', 'string', 'size:36'],
        ]);

        try {
            $payload = $this->challenge->resend((string) $data['challenge_token']);
        } catch (AuthException $e) {
            return $this->failure($this->err($e), [], 422);
        }

        return $this->success('2FA code resent.', $payload);
    }
}
