<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Joe404\LaravelAuth\Http\Concerns\ResolvesMessages;
use Joe404\LaravelAuth\Http\Concerns\RespondsWithJson;
use Joe404\LaravelAuth\Models\AuthSessionExtended;
use Joe404\LaravelAuth\Models\AuthUserDevice;
use Joe404\LaravelAuth\Services\UserDeviceService;

/**
 * Surfaces the user's permanent device history.
 *
 * Distinct from /auth/sessions: sessions list ACTIVE logins (current
 * cookies/tokens you can revoke), while devices list EVERY physical or
 * logical device that has ever logged in — including ones the user has
 * since logged out of. Useful for spotting stolen credentials: if a
 * device shows up that the user does not recognise, someone else has
 * the password.
 */
class UserDeviceController extends Controller
{
    use ResolvesMessages, RespondsWithJson;

    public function __construct(
        private readonly UserDeviceService $userDeviceService,
    ) {}

    /**
     * GET /auth/devices
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user === null) {
            return $this->failure($this->errKey('unauthenticated', 'Unauthenticated.'), [], 401);
        }

        // Active fingerprint hashes from the live sessions table so we
        // can flag "has an active session right now" per device row.
        $activeHashes = AuthSessionExtended::where('user_id', $user->getKey())
            ->whereNotNull('fingerprint_hash')
            ->pluck('fingerprint_hash')
            ->all();

        $devices = $this->userDeviceService->listForUser((int) $user->getKey())
            ->map(fn (AuthUserDevice $d): array => [
                'id'                    => $d->id,
                'platform'              => $d->platform,
                'browser'               => $d->browser,
                'os'                    => $d->os,
                'device_model'          => $d->device_model,
                'device_marketing_name' => $d->device_marketing_name,
                'device_platform'       => $d->device_platform,
                'country'               => $d->country,
                'city'                  => $d->city,
                'ip_address'            => $d->ip_address,
                'first_seen_at'         => $d->first_seen_at?->toIso8601String(),
                'last_seen_at'          => $d->last_seen_at?->toIso8601String(),
                'has_active_session'    => $d->fingerprint_hash !== null
                    && in_array($d->fingerprint_hash, $activeHashes, true),
            ])
            ->all();

        return $this->success(
            $this->msg('devices_retrieved', 'Devices retrieved.'),
            ['devices' => $devices],
        );
    }

    /**
     * DELETE /auth/devices/{id}
     *
     * Forget a device. The row is removed from history so it stops
     * blocking referral attempts and disappears from the device list.
     *
     * Note: this also revokes any active sessions tied to the device,
     * because keeping a session alive for a device the user just
     * "forgot" would be inconsistent — the next request from that
     * session would re-insert the device row anyway.
     */
    public function destroy(string $id, Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user === null) {
            return $this->failure($this->errKey('unauthenticated', 'Unauthenticated.'), [], 401);
        }

        /** @var AuthUserDevice|null $device */
        $device = AuthUserDevice::where('id', $id)
            ->where('user_id', $user->getKey())
            ->first();

        if ($device === null) {
            return $this->failure(
                $this->errKey('device_not_found', 'Device not found.'),
                [],
                404,
            );
        }

        // Revoke any active sessions whose fingerprint matches the
        // device we're forgetting, so the device truly disappears.
        if ($device->fingerprint_hash !== null) {
            AuthSessionExtended::where('user_id', $user->getKey())
                ->where('fingerprint_hash', $device->fingerprint_hash)
                ->delete();
        }

        $device->delete();

        return $this->success(
            $this->msg('device_forgotten', 'Device forgotten.'),
            [],
        );
    }
}
