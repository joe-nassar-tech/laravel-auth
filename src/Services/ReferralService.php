<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Services;

use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Joe404\LaravelAuth\Contracts\ReferralRewardHandlerContract;
use Joe404\LaravelAuth\Events\ReferralCreated;
use Joe404\LaravelAuth\Events\ReferralRedeemed;
use Joe404\LaravelAuth\Events\SuspiciousReferralDetected;
use Joe404\LaravelAuth\Exceptions\AuthException;
use Joe404\LaravelAuth\Models\AuthUserDevice;
use Joe404\LaravelAuth\Models\Referral;

/**
 * Core referral logic — code validation, abuse detection, reward dispatch.
 *
 * Reads request context (IP + fingerprint hash) to compare against the
 * referrer's historical fingerprint and decides whether the referral is
 * valid / suspicious / blocked according to the configured policy.
 *
 * Registration is NEVER aborted by this service. The worst it can do is
 * persist the referral with status=blocked and suppress the reward.
 *
 * Hard rules (not config-overridable):
 *   - Referral code must exist in the users table (clear error if not)
 *   - You cannot use your own referral code (clear error, no record stored)
 *   - Each new user can only ever be referred once (clear error)
 */
class ReferralService
{
    public function __construct(
        private readonly DeviceService $deviceService,
        private readonly UserDeviceService $userDeviceService,
    ) {}

    /**
     * Apply a referral code submitted during registration.
     *
     * Returns the persisted Referral, or null when the request's client
     * type is not allowed by config (silent fail — caller treats as
     * "no referral was submitted").
     *
     * @throws AuthException when the code is invalid, self-referral, or
     *                       the user has already been referred.
     */
    public function applyAtRegistration(User $newUser, string $code, Request $request): ?Referral
    {
        if (! $this->clientAllowed($request)) {
            return null;
        }

        return $this->createReferral($newUser, $code, $request);
    }

    /**
     * Apply a referral code submitted by an already-registered user via
     * the redeem endpoint.
     *
     * @throws AuthException for window-expired, code-not-found, self-referral, already-redeemed.
     */
    public function redeem(User $user, string $code, Request $request): Referral
    {
        if (! $this->clientAllowed($request)) {
            // Silent fail when the client type is disallowed: respond as if
            // it succeeded but persist nothing. Controller handles the 200.
            throw new SilentReferralFailure();
        }

        $window = max(0, (int) config('auth_system.referral_code.redeem_window_minutes', 120));

        if ($window > 0) {
            $createdAt = $user->created_at instanceof \DateTimeInterface
                ? Carbon::instance($user->created_at)
                : null;

            if ($createdAt === null || $createdAt->addMinutes($window)->isPast()) {
                throw new AuthException(
                    'Referral code can no longer be redeemed. The redemption window has passed.',
                    'referral_window_expired',
                );
            }
        }

        return $this->createReferral($user, $code, $request);
    }

    /**
     * Shared create-referral path used by both registration and redeem.
     *
     * @throws AuthException
     */
    private function createReferral(User $newUser, string $code, Request $request): Referral
    {
        $code = trim($code);

        if ($code === '') {
            throw new AuthException('Referral code is required.', 'referral_code_not_found');
        }

        if (Referral::where('referred_id', $newUser->getKey())->exists()) {
            throw new AuthException(
                'You have already redeemed a referral code.',
                'referral_already_redeemed',
            );
        }

        $referrer = $this->findReferrerByCode($code);

        if ($referrer === null) {
            throw new AuthException('Referral code not found.', 'referral_code_not_found');
        }

        if ((int) $referrer->getKey() === (int) $newUser->getKey()) {
            throw new AuthException(
                'You cannot use your own referral code.',
                'referral_self_referral',
            );
        }

        $newFingerprint = $this->currentFingerprint($request);
        $newIp          = $newFingerprint['ip_address'] ?? $request->ip();
        $newHash        = $newFingerprint['fingerprint_hash'] ?? null;

        // Scan the ENTIRE device history of the referrer — every phone
        // and browser they have ever logged in from. This closes the
        // bypass where an attacker logs in on two devices then logs out
        // of one to remove it from the active-sessions table before
        // creating a self-referred account from that device. Device
        // history rows are never deleted on logout.
        $referrerId = (int) $referrer->getKey();

        $deviceMatch = $this->userDeviceService->userHasDeviceWithFingerprint($referrerId, $newHash);
        $ipMatch     = $this->userDeviceService->userHasDeviceWithIp($referrerId, $newIp);

        // Snapshot strings stored on the referral row are for audit only —
        // they represent "what we compared against" so an admin reviewing
        // a flagged referral later can see the referrer's most recent
        // observed device. Matching itself was full-history above.
        $latestReferrerDevice = $this->userDeviceService->mostRecent($referrerId);
        $referrerHash         = $latestReferrerDevice?->fingerprint_hash;
        $referrerIp           = $latestReferrerDevice?->ip_address;

        [$status, $reason] = $this->resolveStatus($ipMatch, $deviceMatch);

        /** @var Referral $referral */
        $referral = DB::transaction(function () use (
            $newUser,
            $referrer,
            $code,
            $status,
            $referrerHash,
            $newHash,
            $referrerIp,
            $newIp,
            $ipMatch,
            $deviceMatch,
        ): Referral {
            // Race guard inside the transaction in case a concurrent
            // request slipped past the early-exit check.
            if (Referral::where('referred_id', $newUser->getKey())->exists()) {
                throw new AuthException(
                    'You have already redeemed a referral code.',
                    'referral_already_redeemed',
                );
            }

            return Referral::create([
                'referrer_id'          => $referrer->getKey(),
                'referred_id'          => $newUser->getKey(),
                'referral_code'        => $code,
                'status'               => $status,
                'referrer_fingerprint' => $referrerHash,
                'referred_fingerprint' => $newHash,
                'referrer_ip'          => $referrerIp,
                'referred_ip'          => $newIp,
                'ip_match'             => $ipMatch,
                'device_match'         => $deviceMatch,
            ]);
        });

        ReferralCreated::dispatch($referral);

        if ($status === Referral::STATUS_VALID) {
            $this->dispatchReward($referral);
        }

        if (in_array($status, [Referral::STATUS_SUSPICIOUS, Referral::STATUS_BLOCKED], true)) {
            SuspiciousReferralDetected::dispatch($referral, $reason);
        }

        return $referral;
    }

    /**
     * Apply the developer's reward handler for a referral that just
     * became valid. Failure here rolls the referral back to "pending"
     * so the host can retry (e.g. via a queue listener on
     * ReferralCreated). The package never silently swallows reward
     * errors — we log them so the developer can find them.
     */
    private function dispatchReward(Referral $referral): void
    {
        $handlerClass = config('auth_system.referral_code.reward_handler');

        if (! is_string($handlerClass) || $handlerClass === '' || ! class_exists($handlerClass)) {
            // No handler configured → event-only mode. The developer
            // listens to ReferralCreated themselves.
            return;
        }

        $handler = app($handlerClass);

        if (! $handler instanceof ReferralRewardHandlerContract) {
            Log::warning('[laravel-auth] Referral reward handler does not implement ReferralRewardHandlerContract.', [
                'class' => $handlerClass,
            ]);

            return;
        }

        try {
            $referral->loadMissing(['referrer', 'referred']);
            $handler->handle($referral);

            $referral->update(['redeemed_at' => now()]);
            ReferralRedeemed::dispatch($referral->refresh());
        } catch (\Throwable $e) {
            // Reset to pending so the host can retry the reward without
            // the referral row being permanently stuck in "valid but
            // never redeemed".
            $referral->update(['status' => Referral::STATUS_PENDING]);

            Log::error('[laravel-auth] Referral reward handler threw.', [
                'referral_id' => $referral->id,
                'handler'     => $handlerClass,
                'exception'   => $e,
            ]);

            throw $e;
        }
    }

    /**
     * Admin override: manually flip a referral's status. When moving to
     * "valid" we run the reward handler so the override path is the same
     * as the auto path.
     */
    public function overrideStatus(Referral $referral, string $newStatus, ?string $note = null): Referral
    {
        $allowed = [
            Referral::STATUS_PENDING,
            Referral::STATUS_VALID,
            Referral::STATUS_SUSPICIOUS,
            Referral::STATUS_BLOCKED,
            Referral::STATUS_EXPIRED,
        ];

        if (! in_array($newStatus, $allowed, true)) {
            throw new AuthException('Invalid referral status.', 'referral_status_invalid');
        }

        $previous = $referral->status;
        $referral->update([
            'status'     => $newStatus,
            'admin_note' => $note,
        ]);

        if ($newStatus === Referral::STATUS_VALID
            && $previous !== Referral::STATUS_VALID
            && $referral->redeemed_at === null
        ) {
            $this->dispatchReward($referral->refresh());
        }

        return $referral->refresh();
    }

    /**
     * Resolve the action for the matched abuse signals according to the
     * configured policy. Returns [status, human-readable reason].
     *
     * Order of precedence: same_ip_and_device beats same_device beats
     * same_ip — so a full match always uses the strictest rule.
     *
     * @return array{0: string, 1: string}
     */
    private function resolveStatus(bool $ipMatch, bool $deviceMatch): array
    {
        $abuse = (array) config('auth_system.referral_code.abuse', []);

        if ($ipMatch && $deviceMatch) {
            return [
                $this->actionToStatus((string) ($abuse['on_same_ip_and_device'] ?? 'block')),
                'Same IP and same device as referrer.',
            ];
        }

        if ($deviceMatch) {
            return [
                $this->actionToStatus((string) ($abuse['on_same_device'] ?? 'block')),
                'Same device as referrer (different IP).',
            ];
        }

        if ($ipMatch) {
            return [
                $this->actionToStatus((string) ($abuse['on_same_ip'] ?? 'flag')),
                'Same IP as referrer (different device).',
            ];
        }

        return [Referral::STATUS_VALID, 'No abuse signal matched.'];
    }

    private function actionToStatus(string $action): string
    {
        return match (strtolower($action)) {
            'block'  => Referral::STATUS_BLOCKED,
            'flag'   => Referral::STATUS_SUSPICIOUS,
            'ignore' => Referral::STATUS_VALID,
            default  => Referral::STATUS_SUSPICIOUS,
        };
    }

    private function clientAllowed(Request $request): bool
    {
        $allowed = strtolower((string) config('auth_system.referral_code.allowed_clients', 'both'));

        if ($allowed === 'both') {
            return true;
        }

        $isMobile = strtolower((string) $request->header('X-Client-Type', '')) === 'mobile';

        return match ($allowed) {
            'mobile' => $isMobile,
            'web'    => ! $isMobile,
            default  => true,
        };
    }

    /**
     * Look up the user that owns a referral code. The column is
     * configurable so this works against whatever schema the host app
     * uses for storing referral codes on its users table.
     */
    private function findReferrerByCode(string $code): ?User
    {
        /** @var class-string<User> $userModel */
        $userModel = config('auth.providers.users.model', \App\Models\User::class);
        $column    = (string) config('auth_system.referral_code.column', 'referral_code');

        /** @var User|null $referrer */
        $referrer = $userModel::query()->where($column, $code)->first();

        return $referrer;
    }

    /**
     * @return array{ip_address: string|null, fingerprint_hash: string|null}
     */
    private function currentFingerprint(Request $request): array
    {
        /** @var array<string, mixed> $fp */
        $fp = $request->get('_device', []);

        if (empty($fp)) {
            $fp = $this->deviceService->fingerprint($request);
        }

        return [
            'ip_address'       => $fp['ip_address'] ?? $request->ip(),
            'fingerprint_hash' => $fp['fingerprint_hash'] ?? null,
        ];
    }

}
