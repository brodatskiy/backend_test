<?php

namespace App\Http\Controllers;

use App\Models\Referral;
use App\Models\ReferralEarning;
use App\Services\Referral\ReferralService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReferralsController extends Controller
{
    public function earnings(Request $request): JsonResponse
    {
        $master = $request->attributes->get('current_master');

        if ($master === null) {
            return response()->json(['message' => 'Текущий мастер не найден.'], 401);
        }

        $amountsByStatus = $master->referralEarnings()
            ->selectRaw('status, SUM(amount) AS total_amount')
            ->groupBy('status')
            ->pluck('total_amount', 'status');

        $rewardedReferralsCount = $master->referrals()
            ->where('status', Referral::STATUS_REWARDED)
            ->count();

        $data = [
            'total_amount' => (int) $amountsByStatus->sum(),
            'pending_amount' => (int) $amountsByStatus->get(ReferralEarning::STATUS_PENDING, 0),
            'paid_amount' => (int) $amountsByStatus->get(ReferralEarning::STATUS_PAID, 0),
            'rewarded_referrals_count' => $rewardedReferralsCount,
        ];

        return response()->json(['data' => $data]);
    }

    public function my(Request $request): JsonResponse
    {
        $master = $request->attributes->get('current_master');

        if ($master === null) {
            return response()->json(['message' => 'Текущий мастер не найден.'], 401);
        }

        $referrals = $master->referrals()
            ->with('referredMaster:id,name')
            ->withSum('earnings', 'amount')
            ->orderBy('id')
            ->get()
            ->map(fn (Referral $referral) => [
                'name' => $referral->referredMaster->name,
                'attached_at' => $referral->created_at->toISOString(),
                'is_rewarded' => $referral->status === Referral::STATUS_REWARDED,
                'earned_amount' => (int) ($referral->earnings_sum_amount ?? 0),
            ]);

        return response()->json(['data' => $referrals]);
    }

    public function attach(Request $request, ReferralService $referralService): JsonResponse
    {
        $master = $request->attributes->get('current_master');

        if ($master === null) {
            return response()->json(['message' => 'Текущий мастер не найден.'], 401);
        }

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:255'],
        ]);

        $referral = $referralService->registerReferral($master, $validated['code']);

        if ($referral === null) {
            return response()->json(['code' => 'Код не найден или принадлежит текущему мастеру.'], 422);
        }

        return response()->json(['data' => $referral], $referral->wasRecentlyCreated ? 201 : 200);
    }
}
