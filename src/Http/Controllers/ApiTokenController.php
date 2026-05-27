<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Joe404\LaravelAuth\Http\Concerns\ResolvesMessages;
use Joe404\LaravelAuth\Http\Concerns\RespondsWithJson;
use Joe404\LaravelAuth\Http\Requests\ApiTokenRequest;
use Joe404\LaravelAuth\Http\Requests\ApiTokenUpdateRequest;
use Joe404\LaravelAuth\Models\AuthApiToken;
use Joe404\LaravelAuth\Services\ApiTokenService;

class ApiTokenController extends Controller
{
    use ResolvesMessages, RespondsWithJson;

    public function __construct(
        private readonly ApiTokenService $apiTokenService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tokens = $this->apiTokenService->list(
            get_class($request->user()),
            $request->user()->getKey(),
        );

        return $this->success($this->msg('api_tokens_retrieved', 'API tokens retrieved.'), ['tokens' => $tokens]);
    }

    public function store(ApiTokenRequest $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();

        // Resolve to the EFFECTIVE abilities first: an empty or absent list
        // falls back to abilities_default (mirroring ApiTokenService::issue).
        // Doing it here — before the strict check, and passing the resolved list
        // to issue() — ensures the strict allow-list validates exactly what will
        // be granted. Otherwise `abilities: []` would pass the check (empty diff)
        // and then be expanded to abilities_default unchecked.
        $abilities = $data['abilities'] ?? [];
        if ($abilities === []) {
            $abilities = (array) config('auth_system.api_tokens.abilities_default', ['read']);
        }

        // Strict mode (opt-in via api_tokens.strict_abilities): a normal user
        // may only self-grant abilities on the configured allow-list, and never
        // the "*" wildcard — that is reserved for admin-issued tokens. This
        // closes the privilege-escalation path where any user could mint a
        // token that passes any auth.api-token:<ability> gate.
        if ((bool) config('auth_system.api_tokens.strict_abilities', false)) {
            $requested = array_map('strval', $abilities);
            $grantable = array_map('strval', (array) config('auth_system.api_tokens.grantable_abilities', ['read']));
            $invalid   = array_values(array_diff($requested, $grantable));

            if (in_array('*', $requested, true) || $invalid !== []) {
                return $this->failure(
                    'One or more requested abilities are not permitted for self-issued tokens.',
                    ['abilities' => in_array('*', $requested, true) ? ['*'] : $invalid],
                    422,
                );
            }
        }

        $result = $this->apiTokenService->issue(
            $data['name'],
            $abilities,
            $this->cappedExpiry($data['expires_in_days'] ?? null),
            $user,
        );

        return $this->success(
            $this->msg('api_token_created', 'API token created. Store it securely — it will not be shown again.'),
            ['raw_token' => $result['raw_token'], 'token' => $result['token']],
            201,
        );
    }

    /**
     * Apply the optional api_tokens.max_ttl_days hard cap to a user-requested
     * lifetime. With no cap configured the request is honored as-is; with a cap
     * a non-expiring request is forced to the cap and an over-cap request is
     * clamped down. Discourages indefinitely-lived self-issued tokens.
     */
    private function cappedExpiry(?int $requestedDays): ?int
    {
        $max = config('auth_system.api_tokens.max_ttl_days');

        if ($max === null) {
            return $requestedDays;
        }

        $max = (int) $max;

        return $requestedDays === null ? $max : min($requestedDays, $max);
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

        return $this->success($this->msg('api_token_revoked', 'API token revoked.'));
    }

    public function adminIndex(Request $request): JsonResponse
    {
        $tokens = $this->apiTokenService->list();

        return $this->success($this->msg('api_tokens_retrieved', 'API tokens retrieved.'), ['tokens' => $tokens]);
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
            $this->msg('api_token_created', 'API token created. Store it securely — it will not be shown again.'),
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

        return $this->success($this->msg('api_token_updated', 'API token updated.'), ['token' => $token->fresh()]);
    }

    public function adminDestroy(Request $request, int $id): JsonResponse
    {
        $this->apiTokenService->revoke($id);

        return $this->success($this->msg('api_token_revoked', 'API token revoked.'));
    }
}
