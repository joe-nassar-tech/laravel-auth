<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Joe404\LaravelAuth\Http\Concerns\RespondsWithJson;
use Joe404\LaravelAuth\Http\Requests\ApiTokenRequest;
use Joe404\LaravelAuth\Http\Requests\ApiTokenUpdateRequest;
use Joe404\LaravelAuth\Models\AuthApiToken;
use Joe404\LaravelAuth\Services\ApiTokenService;

class ApiTokenController extends Controller
{
    use RespondsWithJson;

    public function __construct(
        private readonly ApiTokenService $apiTokenService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tokens = $this->apiTokenService->list(
            get_class($request->user()),
            $request->user()->getKey(),
        );

        return $this->success('API tokens retrieved.', ['tokens' => $tokens]);
    }

    public function store(ApiTokenRequest $request): JsonResponse
    {
        $user   = $request->user();
        $data   = $request->validated();
        $result = $this->apiTokenService->issue(
            $data['name'],
            $data['abilities'] ?? ['read'],
            $data['expires_in_days'] ?? null,
            $user,
        );

        return $this->success(
            'API token created. Store it securely — it will not be shown again.',
            ['raw_token' => $result['raw_token'], 'token' => $result['token']],
            201,
        );
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $token = AuthApiToken::where('id', $id)
            ->where('owner_type', get_class($request->user()))
            ->where('owner_id', $request->user()->getKey())
            ->first();

        if ($token === null) {
            return $this->failure('Token not found.', [], 404);
        }

        $this->apiTokenService->revoke($id);

        return $this->success('API token revoked.');
    }

    public function adminIndex(Request $request): JsonResponse
    {
        $tokens = $this->apiTokenService->list();

        return $this->success('API tokens retrieved.', ['tokens' => $tokens]);
    }

    public function adminStore(ApiTokenRequest $request): JsonResponse
    {
        $data   = $request->validated();
        $result = $this->apiTokenService->issue(
            $data['name'],
            $data['abilities'] ?? ['read'],
            $data['expires_in_days'] ?? null,
        );

        return $this->success(
            'API token created. Store it securely — it will not be shown again.',
            ['raw_token' => $result['raw_token'], 'token' => $result['token']],
            201,
        );
    }

    public function adminUpdate(ApiTokenUpdateRequest $request, int $id): JsonResponse
    {
        $token = AuthApiToken::where('id', $id)->first();

        if ($token === null) {
            return $this->failure('Token not found.', [], 404);
        }

        $expiresInDays = $request->validated('expires_in_days');

        if ($request->has('expires_in_days')) {
            $expiresAt = $expiresInDays !== null ? now()->addDays($expiresInDays) : null;
        } else {
            $expiresAt = $token->expires_at;
        }

        AuthApiToken::where('id', $id)->update([
            'abilities'  => $request->validated('abilities', $token->abilities),
            'expires_at' => $expiresAt,
        ]);

        return $this->success('API token updated.', ['token' => $token->fresh()]);
    }

    public function adminDestroy(Request $request, int $id): JsonResponse
    {
        $this->apiTokenService->revoke($id);

        return $this->success('API token revoked.');
    }
}
