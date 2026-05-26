<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Joe404\LaravelAuth\Exceptions\AuthException;
use Joe404\LaravelAuth\Http\Concerns\ResolvesMessages;
use Joe404\LaravelAuth\Http\Concerns\RespondsWithJson;
use Joe404\LaravelAuth\Http\Requests\TwoFactorEnrollRequest;
use Joe404\LaravelAuth\Models\AuthTwoFactorMethod;
use Joe404\LaravelAuth\Services\BackupCodeService;
use Joe404\LaravelAuth\Services\TwoFactorService;

class TwoFactorController extends Controller
{
    use ResolvesMessages, RespondsWithJson;

    public function __construct(
        private readonly TwoFactorService $twoFactor,
        private readonly BackupCodeService $backupCodes,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->assertFeatureEnabled();

        $user = $request->user();

        $methods = AuthTwoFactorMethod::query()
            ->where('user_id', $user->getKey())
            ->orderBy('id')
            ->get(['id', 'type', 'is_default', 'verified_at', 'last_used_at', 'created_at']);

        return $this->success('2FA methods retrieved.', [
            'methods'      => $methods,
            'backup_codes' => $this->backupCodes->summary($user),
        ]);
    }

    public function startEnroll(Request $request, string $method): JsonResponse
    {
        $this->assertFeatureEnabled();

        try {
            $payload = $this->twoFactor->startEnrollment($request->user(), $method);
        } catch (AuthException $e) {
            return $this->failure($this->err($e), [], 422);
        }

        return $this->success('Enrollment started.', $payload);
    }

    public function verifyEnroll(TwoFactorEnrollRequest $request, string $method): JsonResponse
    {
        $this->assertFeatureEnabled();

        try {
            $verified = $this->twoFactor->verifyEnrollment(
                $request->user(),
                $method,
                (string) $request->string('code'),
            );
        } catch (AuthException $e) {
            return $this->failure($this->err($e), [], 422);
        }

        $payload = [
            'id'         => $verified->id,
            'type'       => $verified->type,
            'is_default' => (bool) $verified->is_default,
        ];

        // Backup codes are returned ONCE, on the first method's enrollment.
        $backup = $verified->getAttribute('backup_codes');
        if (is_array($backup) && $backup !== []) {
            $payload['backup_codes'] = $backup;
        }

        return $this->success('2FA method enrolled.', $payload);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->assertFeatureEnabled();

        try {
            $this->twoFactor->disable($request->user(), $id);
        } catch (AuthException $e) {
            return $this->failure($this->err($e), [], 422);
        }

        return $this->success('2FA method removed.');
    }

    public function setDefault(Request $request, int $id): JsonResponse
    {
        $this->assertFeatureEnabled();

        try {
            $method = $this->twoFactor->setDefault($request->user(), $id);
        } catch (AuthException $e) {
            return $this->failure($this->err($e), [], 422);
        }

        return $this->success('Default 2FA method updated.', [
            'id'   => $method->id,
            'type' => $method->type,
        ]);
    }

    public function regenerateBackupCodes(Request $request): JsonResponse
    {
        $this->assertFeatureEnabled();

        $codes = $this->backupCodes->generate($request->user());

        return $this->success('Backup codes regenerated.', [
            'backup_codes' => $codes,
        ]);
    }

    public function backupCodesSummary(Request $request): JsonResponse
    {
        $this->assertFeatureEnabled();

        return $this->success('Backup codes summary.', $this->backupCodes->summary($request->user()));
    }

    private function assertFeatureEnabled(): void
    {
        if (! (bool) config('auth_system.two_factor.enabled', true)) {
            abort(404);
        }
    }
}
