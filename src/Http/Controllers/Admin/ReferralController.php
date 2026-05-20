<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Joe404\LaravelAuth\Exceptions\AuthException;
use Joe404\LaravelAuth\Http\Concerns\ResolvesMessages;
use Joe404\LaravelAuth\Http\Concerns\RespondsWithJson;
use Joe404\LaravelAuth\Http\Requests\ReferralAdminUpdateRequest;
use Joe404\LaravelAuth\Models\Referral;
use Joe404\LaravelAuth\Services\ReferralService;

class ReferralController extends Controller
{
    use ResolvesMessages, RespondsWithJson;

    public function __construct(
        private readonly ReferralService $referralService,
    ) {}

    /**
     * GET /auth/admin/referrals
     *
     * Paginated cross-tenant view of every referral in the system.
     * Filterable by ?status= to triage flagged ones quickly.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = max(1, min(100, (int) $request->integer('per_page', 20)));
        $status  = $request->query('status');

        $query = Referral::query()->latest('id');

        if (is_string($status) && $status !== '') {
            $query->where('status', $status);
        }

        $page = $query->paginate($perPage);

        return $this->success(
            $this->msg('referrals_retrieved', 'Referrals retrieved.'),
            [
                'referrals' => $page->map(fn (Referral $r): array => $this->serialize($r))->all(),
                'meta'      => [
                    'current_page' => $page->currentPage(),
                    'per_page'     => $page->perPage(),
                    'total'        => $page->total(),
                    'last_page'    => $page->lastPage(),
                ],
            ],
        );
    }

    /**
     * PATCH /auth/admin/referrals/{id}
     *
     * Manually override a referral's status. Transitioning to "valid"
     * runs the reward handler the same way an auto-valid would, so the
     * override path stays consistent with the registration path.
     */
    public function update(string $id, ReferralAdminUpdateRequest $request): JsonResponse
    {
        $referral = Referral::find($id);

        if ($referral === null) {
            return $this->failure(
                $this->errKey('referral_not_found', 'Referral not found.'),
                [],
                404,
            );
        }

        try {
            $referral = $this->referralService->overrideStatus(
                $referral,
                $request->string('status')->toString(),
                $request->filled('note') ? $request->string('note')->toString() : null,
            );
        } catch (AuthException $e) {
            return $this->failure($this->err($e), [], 422);
        }

        return $this->success(
            $this->msg('referral_status_updated', 'Referral status updated.'),
            ['referral' => $this->serialize($referral)],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(Referral $r): array
    {
        return [
            'id'                   => $r->id,
            'referrer_id'          => $r->referrer_id,
            'referred_id'          => $r->referred_id,
            'referral_code'        => $r->referral_code,
            'status'               => $r->status,
            'ip_match'             => (bool) $r->ip_match,
            'device_match'         => (bool) $r->device_match,
            'referrer_ip'          => $r->referrer_ip,
            'referred_ip'          => $r->referred_ip,
            'referrer_fingerprint' => $r->referrer_fingerprint,
            'referred_fingerprint' => $r->referred_fingerprint,
            'admin_note'           => $r->admin_note,
            'redeemed_at'          => $r->redeemed_at?->toIso8601String(),
            'created_at'           => $r->created_at?->toIso8601String(),
            'updated_at'           => $r->updated_at?->toIso8601String(),
        ];
    }
}
