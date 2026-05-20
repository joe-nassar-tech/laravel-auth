<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Joe404\LaravelAuth\Exceptions\AuthException;
use Joe404\LaravelAuth\Http\Concerns\ResolvesMessages;
use Joe404\LaravelAuth\Http\Concerns\RespondsWithJson;
use Joe404\LaravelAuth\Http\Requests\ReferralRedeemRequest;
use Joe404\LaravelAuth\Models\Referral;
use Joe404\LaravelAuth\Services\ReferralService;
use Joe404\LaravelAuth\Services\SilentReferralFailure;

class ReferralController extends Controller
{
    use ResolvesMessages, RespondsWithJson;

    public function __construct(
        private readonly ReferralService $referralService,
    ) {}

    /**
     * POST /auth/referrals/redeem
     *
     * Fallback path for users who forgot to enter the referral code on
     * the registration form. Same abuse checks as the registration path
     * apply. Bounded by the configured window (default: 2 hours from
     * account creation).
     */
    public function redeem(ReferralRedeemRequest $request): JsonResponse
    {
        $user = $request->user();

        if ($user === null) {
            return $this->failure($this->errKey('unauthenticated', 'Unauthenticated.'), [], 401);
        }

        try {
            $referral = $this->referralService->redeem(
                $user,
                $request->string('referral_code')->toString(),
                $request,
            );
        } catch (SilentReferralFailure) {
            // Wrong client type per config → silent fail. We respond as
            // if it succeeded but nothing was stored or dispatched.
            return $this->success(
                $this->msg('referral_redeemed', 'Referral code submitted.'),
                ['status' => null],
            );
        } catch (AuthException $e) {
            return $this->failure($this->err($e), [], 422);
        }

        return $this->success(
            $this->msg('referral_redeemed', 'Referral code submitted.'),
            ['status' => $referral->status, 'id' => $referral->id],
        );
    }

    /**
     * GET /auth/referrals
     *
     * List the authenticated user's referrals — i.e. the people who
     * registered using their code, with each referral's current status.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user === null) {
            return $this->failure($this->errKey('unauthenticated', 'Unauthenticated.'), [], 401);
        }

        $referrals = Referral::query()
            ->where('referrer_id', $user->getKey())
            ->latest('id')
            ->get()
            ->map(fn (Referral $r): array => [
                'id'           => $r->id,
                'status'       => $r->status,
                'redeemed_at'  => $r->redeemed_at?->toIso8601String(),
                'created_at'   => $r->created_at?->toIso8601String(),
                'referred'     => [
                    'id' => $r->referred_id,
                ],
            ])
            ->all();

        return $this->success(
            $this->msg('referrals_retrieved', 'Referrals retrieved.'),
            ['referrals' => $referrals],
        );
    }

    /**
     * GET /auth/referrals/stats
     *
     * Aggregate counts across the authenticated user's referrals.
     */
    public function stats(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user === null) {
            return $this->failure($this->errKey('unauthenticated', 'Unauthenticated.'), [], 401);
        }

        $rows = Referral::query()
            ->selectRaw('status, COUNT(*) as count')
            ->where('referrer_id', $user->getKey())
            ->groupBy('status')
            ->pluck('count', 'status')
            ->all();

        $stats = [
            'total'      => array_sum($rows),
            'pending'    => (int) ($rows[Referral::STATUS_PENDING] ?? 0),
            'valid'      => (int) ($rows[Referral::STATUS_VALID] ?? 0),
            'suspicious' => (int) ($rows[Referral::STATUS_SUSPICIOUS] ?? 0),
            'blocked'    => (int) ($rows[Referral::STATUS_BLOCKED] ?? 0),
            'expired'    => (int) ($rows[Referral::STATUS_EXPIRED] ?? 0),
        ];

        return $this->success(
            $this->msg('referral_stats_retrieved', 'Referral stats retrieved.'),
            ['stats' => $stats],
        );
    }
}
