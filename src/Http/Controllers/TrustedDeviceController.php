<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Hash;
use Joe404\LaravelAuth\Exceptions\AuthException;
use Joe404\LaravelAuth\Http\Concerns\ResolvesMessages;
use Joe404\LaravelAuth\Http\Concerns\RespondsWithJson;
use Joe404\LaravelAuth\Models\AuthTrustedDevice;
use Joe404\LaravelAuth\Services\TrustedDeviceService;
use Joe404\LaravelAuth\Services\TrustLevelResolver;
use Joe404\LaravelAuth\Services\TwoFactorService;

class TrustedDeviceController extends Controller
{
    use ResolvesMessages, RespondsWithJson;

    public function __construct(
        private readonly TrustedDeviceService $trustedDevices,
        private readonly TrustLevelResolver $resolver,
        private readonly TwoFactorService $twoFactor,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->assertFeatureEnabled();

        $user = $request->user();

        $devices = AuthTrustedDevice::query()
            ->where('user_id', $user->getKey())
            ->whereNull('revoked_at')
            ->orderByDesc('last_seen_at')
            ->get();

        $current = $this->trustedDevices->currentActive($user, $request);

        $data = $devices->map(function (AuthTrustedDevice $device) use ($current): array {
            return [
                'id'              => $device->id,
                'device_name'     => $device->device_name,
                'platform'        => $device->platform,
                'browser'         => $device->browser,
                'os'              => $device->os,
                'level'           => $this->resolver->resolve($device),
                'stored_level'    => $device->level,
                'admin_granted'   => $device->admin_granted,
                'trusted_at'      => $device->trusted_at?->toIso8601String(),
                'last_seen_at'    => $device->last_seen_at?->toIso8601String(),
                'is_current'      => $current !== null && $current->id === $device->id,
            ];
        })->all();

        return $this->success('Trusted devices retrieved.', ['devices' => $data]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->assertFeatureEnabled();

        if (! $this->confirmStepUp($request)) {
            return $this->failure('Password confirmation required.', [], 422);
        }

        $user = $request->user();
        $actor  = $this->trustedDevices->currentActive($user, $request);
        $target = AuthTrustedDevice::query()
            ->where('user_id', $user->getKey())
            ->where('id', $id)
            ->whereNull('revoked_at')
            ->first();

        if ($target === null) {
            return $this->failure('Trusted device not found.', [], 404);
        }

        if (! $this->trustedDevices->revokerCanRevoke($actor, $target)) {
            return $this->failure(
                'Your device trust level does not permit revoking this device.',
                ['actor_level' => $actor === null ? AuthTrustedDevice::LEVEL_UNTRUSTED : $this->resolver->resolve($actor)],
                403,
            );
        }

        try {
            $this->trustedDevices->revoke($user, $id, 'user');
        } catch (AuthException $e) {
            return $this->failure($this->err($e), [], 422);
        }

        return $this->success('Trusted device revoked.');
    }

    public function destroyAll(Request $request): JsonResponse
    {
        $this->assertFeatureEnabled();

        if (! $this->confirmStepUp($request)) {
            return $this->failure('Password confirmation required.', [], 422);
        }

        $user  = $request->user();
        $actor = $this->trustedDevices->currentActive($user, $request);

        if (! $this->trustedDevices->revokerCanRevokeAll($actor)) {
            return $this->failure('Only trusted devices may revoke all.', [], 403);
        }

        $count = $this->trustedDevices->revokeAll($user, 'user');

        return $this->success('All trusted devices revoked.', ['revoked' => $count]);
    }

    /**
     * Both endpoints require a password confirmation. If the user has 2FA
     * enrolled they must ALSO supply a fresh 2FA code (covered by the
     * Require2FA middleware on the route — see routes/auth.php).
     */
    private function confirmStepUp(Request $request): bool
    {
        $user     = $request->user();
        $password = (string) $request->input('password', '');

        if ($password === '') {
            return false;
        }

        return Hash::check($password, (string) $user->password);
    }

    private function assertFeatureEnabled(): void
    {
        if (! (bool) config('auth_system.trusted_devices.enabled', true)) {
            abort(404);
        }
    }
}
